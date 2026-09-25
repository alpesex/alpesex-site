<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\AgentCockpit\CoordinatorWake;
use AlpesEx\Portal\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const AGENT_NAMES = [
    'coordination', 'satisfaction', 'commercial', 'developpement', 'tests',
    'audiovisuel', 'montage', 'web', 'marketing',
];

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 65536) {
        throw new RuntimeException('Requête trop volumineuse.', 413);
    }
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw === false ? '' : $raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Corps JSON invalide.', 400);
    }
    return $decoded;
}

function textField(array $data, string $key, int $max, bool $required = true): ?string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '') {
        if ($required) {
            throw new RuntimeException("Champ requis : {$key}.", 422);
        }
        return null;
    }
    if (mb_strlen($value) > $max) {
        throw new RuntimeException("Champ trop long : {$key}.", 422);
    }
    return $value;
}

function agentField(array $data, string $key, bool $required = true): ?string
{
    $agent = textField($data, $key, 40, $required);
    if ($agent !== null && !in_array($agent, AGENT_NAMES, true)) {
        throw new RuntimeException('Agent inconnu.', 422);
    }
    return $agent;
}

function startSession(): void
{
    session_name('ALPESEXSESSID');
    session_set_cookie_params([
        'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

function bearerToken(): ?string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    return preg_match('/^Bearer\s+(.+)$/i', $header, $match) === 1 ? trim($match[1]) : null;
}

function isIngestRequest(): bool
{
    $provided = bearerToken();
    $expected = trim((string) ($_ENV['ALPESEX_AGENT_INGEST_TOKEN'] ?? getenv('ALPESEX_AGENT_INGEST_TOKEN') ?: ''));
    return $provided !== null && strlen($expected) >= 32 && hash_equals($expected, $provided);
}

function requireAdmin(PDO $pdo): array
{
    startSession();
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId === null) {
        throw new RuntimeException('Session absente ou expirée.', 401);
    }

    $query = $pdo->prepare('SELECT email, first_name, last_name, status FROM users WHERE id = :id LIMIT 1');
    $query->execute(['id' => $userId]);
    $user = $query->fetch();
    $configured = (string) ($_ENV['ALPESEX_ADMIN_EMAILS'] ?? getenv('ALPESEX_ADMIN_EMAILS') ?: '');
    $allowed = array_filter(array_map(
        static fn (string $email): string => mb_strtolower(trim($email)),
        explode(',', $configured)
    ));
    if (!is_array($user) || $user['status'] !== 'active' || !in_array(mb_strtolower((string) $user['email']), $allowed, true)) {
        throw new RuntimeException('Accès administrateur ALPES\'Ex requis.', 403);
    }

    $_SESSION['agent_cockpit_csrf'] ??= bin2hex(random_bytes(32));
    return [
        'email' => (string) $user['email'],
        'name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
        'csrf' => (string) $_SESSION['agent_cockpit_csrf'],
    ];
}

function requireCsrf(string $expected): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        throw new RuntimeException('Session expirée : rechargez le cockpit.', 403);
    }
}

function ensureDossier(PDO $pdo, ?string $id): void
{
    if ($id === null) {
        return;
    }
    $query = $pdo->prepare('SELECT 1 FROM agent_dossiers WHERE id = :id');
    $query->execute(['id' => $id]);
    if ($query->fetchColumn() === false) {
        throw new RuntimeException('Dossier inconnu.', 422);
    }
}

function recordEvent(PDO $pdo, array $data): void
{
    $dossier = textField($data, 'dossierId', 64, false);
    ensureDossier($pdo, $dossier);
    $source = agentField($data, 'sourceAgent');
    $target = agentField($data, 'targetAgent', false);
    if ($source !== 'coordination' && $target !== 'coordination') {
        throw new RuntimeException('Toute transmission passe par le Coordinateur.', 422);
    }
    if ($source === 'coordination' && $target === 'coordination') {
        throw new RuntimeException('Transmission réflexive interdite.', 422);
    }
    $payload = $data['payload'] ?? null;
    if ($payload !== null && !is_array($payload)) {
        throw new RuntimeException('Le contenu détaillé doit être un objet JSON.', 422);
    }
    $query = $pdo->prepare(
        'INSERT INTO agent_events (dossier_id, source_agent, target_agent, event_type, summary, payload_json)
         VALUES (:dossier, :source, :target, :type, :summary, :payload)'
    );
    $query->execute([
        'dossier' => $dossier,
        'source' => $source,
        'target' => $target,
        'type' => textField($data, 'eventType', 40),
        'summary' => textField($data, 'summary', 500),
        'payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    if ($dossier !== null) {
        $update = $pdo->prepare('UPDATE agent_dossiers SET current_agent = :agent, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute(['agent' => $target ?? $source, 'id' => $dossier]);
    }
}

function upsertDossier(PDO $pdo, array $data): void
{
    $status = textField($data, 'status', 40, false) ?? 'open';
    $priority = textField($data, 'priority', 20, false) ?? 'normal';
    if (!in_array($status, ['open', 'blocked', 'validation', 'closed'], true) || !in_array($priority, ['low', 'normal', 'high', 'critical'], true)) {
        throw new RuntimeException('Statut ou priorité invalide.', 422);
    }
    $query = $pdo->prepare(
        'INSERT INTO agent_dossiers (id, title, client, version, status, priority, current_agent)
         VALUES (:id, :title, :client, :version, :status, :priority, :agent)
         ON DUPLICATE KEY UPDATE title = VALUES(title), client = VALUES(client), version = VALUES(version),
             status = VALUES(status), priority = VALUES(priority), current_agent = VALUES(current_agent), updated_at = CURRENT_TIMESTAMP'
    );
    $query->execute([
        'id' => textField($data, 'dossierId', 64),
        'title' => textField($data, 'title', 190),
        'client' => textField($data, 'client', 190, false),
        'version' => textField($data, 'version', 80, false),
        'status' => $status,
        'priority' => $priority,
        'agent' => agentField($data, 'currentAgent', false),
    ]);
}

function requestDecision(PDO $pdo, array $data): void
{
    $dossier = textField($data, 'dossierId', 64, false);
    ensureDossier($pdo, $dossier);
    if (agentField($data, 'requesterAgent') !== 'coordination') {
        throw new RuntimeException('Les décisions sont transmises par le Coordinateur.', 422);
    }
    $options = $data['options'] ?? null;
    if (!is_array($options) || count($options) < 2 || count($options) > 3) {
        throw new RuntimeException('Une décision doit proposer deux ou trois options.', 422);
    }
    foreach ($options as $option) {
        if (!is_string($option) || trim($option) === '' || mb_strlen($option) > 300) {
            throw new RuntimeException('Option de décision invalide.', 422);
        }
    }
    $urgency = textField($data, 'urgency', 20, false) ?? 'normal';
    if (!in_array($urgency, ['low', 'normal', 'high', 'critical'], true)) {
        throw new RuntimeException('Urgence invalide.', 422);
    }
    $deadline = textField($data, 'deadline', 30, false);
    if ($deadline !== null && strtotime($deadline) === false) {
        throw new RuntimeException('Échéance invalide.', 422);
    }
    $impacts = $data['impacts'] ?? null;
    if ($impacts !== null && !is_array($impacts)) {
        throw new RuntimeException('Les impacts doivent être un objet JSON.', 422);
    }
    $query = $pdo->prepare(
        'INSERT INTO agent_decisions
         (id, dossier_id, requester_agent, question, why_now, options_json, impacts_json, recommendation, urgency, deadline, blocked_work)
         VALUES (:id, :dossier, :agent, :question, :why, :options, :impacts, :recommendation, :urgency, :deadline, :blocked)'
    );
    $query->execute([
        'id' => textField($data, 'decisionId', 64),
        'dossier' => $dossier,
        'agent' => 'coordination',
        'question' => textField($data, 'question', 500),
        'why' => textField($data, 'whyNow', 500, false),
        'options' => json_encode(array_values($options), JSON_UNESCAPED_UNICODE),
        'impacts' => $impacts === null ? null : json_encode($impacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'recommendation' => textField($data, 'recommendation', 500, false),
        'urgency' => $urgency,
        'deadline' => $deadline === null ? null : date('Y-m-d H:i:s', strtotime($deadline)),
        'blocked' => textField($data, 'blockedWork', 500, false),
    ]);
}

function createInput(PDO $pdo, array $data): array
{
    $id = textField($data, 'inputId', 80, false)
        ?? 'IN-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{5,79}$/D', $id) !== 1) {
        throw new RuntimeException('Identifiant d’entrée invalide.', 422);
    }
    $source = textField($data, 'source', 40, false) ?? 'cockpit';
    if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/D', $source) !== 1) {
        throw new RuntimeException('Source d’entrée invalide.', 422);
    }
    $reference = textField($data, 'externalReference', 190, false);
    $title = textField($data, 'title', 190);
    $summary = textField($data, 'summary', 1000);
    $priority = textField($data, 'priority', 20, false) ?? 'normal';
    if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) {
        throw new RuntimeException('Priorité d’entrée invalide.', 422);
    }
    $payload = $data['payload'] ?? null;
    if ($payload !== null && !is_array($payload)) {
        throw new RuntimeException('Le contenu d’entrée doit être un objet JSON.', 422);
    }
    $payloadJson = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $existing = $pdo->prepare(
        'SELECT id, source, external_reference, title, summary, priority, payload_json FROM agent_inputs
         WHERE id=:id OR (source=:source AND external_reference=:reference AND :reference_check IS NOT NULL)
         LIMIT 1 FOR UPDATE'
    );
    $existing->execute(['id' => $id, 'source' => $source, 'reference' => $reference, 'reference_check' => $reference]);
    $previous = $existing->fetch();
    $matches = static fn (array $row): bool => $row['source'] === $source
        && $row['external_reference'] === $reference
        && $row['title'] === $title && $row['summary'] === $summary
        && $row['priority'] === $priority && $row['payload_json'] === $payloadJson;
    if (is_array($previous)) {
        if (!$matches($previous)) {
            throw new RuntimeException('Entrée déjà utilisée avec un contenu différent.', 409);
        }
        return ['inputId' => $previous['id'], 'duplicate' => true];
    }

    $insert = $pdo->prepare(
        'INSERT INTO agent_inputs (id, source, external_reference, title, summary, payload_json, priority)
         VALUES (:id, :source, :reference, :title, :summary, :payload, :priority)'
    );
    try {
        $insert->execute([
            'id' => $id, 'source' => $source, 'reference' => $reference,
            'title' => $title, 'summary' => $summary, 'payload' => $payloadJson, 'priority' => $priority,
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '23000') {
            throw $exception;
        }
        $existing->execute(['id' => $id, 'source' => $source, 'reference' => $reference, 'reference_check' => $reference]);
        $concurrent = $existing->fetch();
        if (!is_array($concurrent) || !$matches($concurrent)) {
            throw new RuntimeException('Entrée déjà utilisée avec un contenu différent.', 409);
        }
        return ['inputId' => $concurrent['id'], 'duplicate' => true];
    }
    return ['inputId' => $id, 'duplicate' => false];
}

function resolveDecision(PDO $pdo, array $data, array $admin): void
{
    $id = textField($data, 'decisionId', 64);
    $choice = textField($data, 'choice', 500);
    $query = $pdo->prepare('SELECT dossier_id, requester_agent, options_json, urgency FROM agent_decisions WHERE id = :id AND status = \'pending\' FOR UPDATE');
    $query->execute(['id' => $id]);
    $decision = $query->fetch();
    if (!is_array($decision)) {
        throw new RuntimeException('Décision absente ou déjà traitée.', 409);
    }
    $options = json_decode((string) $decision['options_json'], true, 16, JSON_THROW_ON_ERROR);
    if (!in_array($choice, $options, true)) {
        throw new RuntimeException('Le choix ne correspond pas aux options proposées.', 422);
    }
    $update = $pdo->prepare(
        'UPDATE agent_decisions SET status = \'decided\', answer = :answer, decision_note = :note,
         decided_by = :author, decided_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $update->execute([
        'answer' => $choice,
        'note' => textField($data, 'note', 1000, false),
        'author' => $admin['email'],
        'id' => $id,
    ]);
    recordEvent($pdo, [
        'dossierId' => $decision['dossier_id'],
        'sourceAgent' => 'coordination',
        'targetAgent' => $decision['requester_agent'] === 'coordination' ? null : $decision['requester_agent'],
        'eventType' => 'decision',
        'summary' => "Décision {$id} validée par Lucas : {$choice}",
        'payload' => ['decisionId' => $id, 'choice' => $choice],
    ]);
    $resumeInput = createInput($pdo, [
        'inputId' => 'DEC-' . $id,
        'source' => 'decision',
        'externalReference' => $id,
        'title' => 'Décision validée pour ' . $decision['dossier_id'],
        'summary' => "Lucas a validé la décision {$id} : {$choice}. Reprendre automatiquement le dossier lié.",
        'priority' => in_array($decision['urgency'], ['low', 'normal', 'high', 'critical'], true)
            ? $decision['urgency']
            : 'normal',
        'payload' => [
            'type' => 'decision_resolved',
            'decisionId' => $id,
            'dossierId' => $decision['dossier_id'],
            'choice' => $choice,
            'note' => textField($data, 'note', 1000, false),
        ],
    ]);
    $linkInput = $pdo->prepare(
        'UPDATE agent_inputs SET dossier_id = :dossier WHERE id = :input AND dossier_id IS NULL'
    );
    $linkInput->execute(['dossier' => $decision['dossier_id'], 'input' => $resumeInput['inputId']]);
}

function notifyCoordinator(CoordinatorWake $wake): void
{
    try {
        $wake->notify();
    } catch (Throwable $exception) {
        error_log('agent-cockpit coordinator wake failed: ' . get_class($exception));
    }
}

function snapshot(PDO $pdo, array $admin): array
{
    $dossiers = $pdo->query(
        'SELECT id, title, client, version, status, priority, current_agent AS currentAgent,
                created_at AS createdAt, updated_at AS updatedAt
         FROM agent_dossiers ORDER BY updated_at DESC LIMIT 100'
    )->fetchAll();
    $events = $pdo->query(
        'SELECT id, dossier_id AS dossierId, source_agent AS sourceAgent, target_agent AS targetAgent,
                event_type AS eventType, summary, payload_json AS payloadJson, created_at AS createdAt
         FROM agent_events ORDER BY created_at DESC, id DESC LIMIT 200'
    )->fetchAll();
    foreach ($events as &$event) {
        $event['payload'] = $event['payloadJson'] === null ? null : json_decode((string) $event['payloadJson'], true);
        unset($event['payloadJson']);
    }
    unset($event);
    $activity = $pdo->query(
        "SELECT a.agent, a.status FROM agent_activity a
         INNER JOIN agent_dossiers d ON d.id=a.dossier_id
         WHERE d.status <> 'closed' ORDER BY a.updated_at DESC"
    )->fetchAll();
    $agentStatuses = array_fill_keys(AGENT_NAMES, 'available');
    foreach ($activity as $item) {
        if ($item['status'] === 'blocked') {
            $agentStatuses[$item['agent']] = 'blocked';
        } elseif ($item['status'] === 'active') {
            $agentStatuses[$item['agent']] = 'active';
        }
    }
    $decisions = $pdo->query(
        'SELECT id, dossier_id AS dossierId, requester_agent AS requesterAgent, question, why_now AS whyNow,
                options_json AS options, impacts_json AS impacts, recommendation, urgency, deadline,
                blocked_work AS blockedWork, status, answer, decision_note AS decisionNote,
                requested_at AS requestedAt, decided_at AS decidedAt
         FROM agent_decisions ORDER BY (status = \'pending\') DESC, requested_at DESC LIMIT 100'
    )->fetchAll();
    foreach ($decisions as &$decision) {
        $decision['options'] = json_decode((string) $decision['options'], true) ?: [];
        $decision['impacts'] = $decision['impacts'] === null ? null : json_decode((string) $decision['impacts'], true);
    }
    unset($decision);
    $inputs = $pdo->query(
        'SELECT id, source, external_reference AS externalReference, title, summary, priority, status,
                dossier_id AS dossierId, result_note AS resultNote, created_at AS createdAt,
                claimed_at AS claimedAt, completed_at AS completedAt
         FROM agent_inputs ORDER BY (status = \'pending\') DESC, created_at DESC LIMIT 100'
    )->fetchAll();
    return [
        'viewer' => ['name' => $admin['name'], 'email' => $admin['email']],
        'csrf' => $admin['csrf'],
        'agents' => AGENT_NAMES,
        'agentStatuses' => $agentStatuses,
        'dossiers' => $dossiers,
        'events' => $events,
        'decisions' => $decisions,
        'inputs' => $inputs,
        'generatedAt' => gmdate('c'),
    ];
}

try {
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    /** @var array{config: AlpesEx\Portal\Config, mailer: AlpesEx\Portal\Mail\Mailer} $services */
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    $coordinatorWake = CoordinatorWake::usingMailer($_ENV, $services['mailer']);
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $admin = requireAdmin($pdo);
        jsonResponse(snapshot($pdo, $admin));
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        jsonResponse(['message' => 'Méthode non autorisée.'], 405);
    }

    $data = body();
    $action = textField($data, 'action', 40);
    if (isIngestRequest()) {
        $pdo->beginTransaction();
        $result = null;
        if ($action === 'input_create') {
            $result = createInput($pdo, $data);
        } else {
            match ($action) {
                'dossier_upsert' => upsertDossier($pdo, $data),
                'event' => recordEvent($pdo, $data),
                'decision_request' => requestDecision($pdo, $data),
                default => throw new RuntimeException('Action agent inconnue.', 422),
            };
        }
        $pdo->commit();
        if ($action === 'input_create' && is_array($result) && $result['duplicate'] === false) {
            notifyCoordinator($coordinatorWake);
        }
        jsonResponse(['ok' => true, 'result' => is_array($result) ? $result : null], 201);
    }

    $admin = requireAdmin($pdo);
    requireCsrf($admin['csrf']);
    if ($action === 'wake_coordinator') {
        (new RateLimiter($pdo))->assertAllowed(
            'coordinator_wake',
            $admin['email'] . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            3,
            300
        );
        if (!$coordinatorWake->notify()) {
            throw new RuntimeException('Le réveil du Coordinateur n’est pas activé sur le serveur.', 503);
        }
        jsonResponse(['ok' => true, 'result' => ['sent' => true]]);
    }
    $pdo->beginTransaction();
    $result = null;
    if ($action === 'resolve_decision') {
        resolveDecision($pdo, $data, $admin);
    } elseif ($action === 'create_input') {
        $result = createInput($pdo, $data);
    } else {
        throw new RuntimeException('Action administrateur inconnue.', 422);
    }
    $pdo->commit();
    if ($action === 'resolve_decision'
        || ($action === 'create_input' && is_array($result) && $result['duplicate'] === false)
    ) {
        notifyCoordinator($coordinatorWake);
    }
    jsonResponse(['ok' => true, 'result' => is_array($result) ? $result : null]);
} catch (JsonException) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['message' => 'Corps JSON invalide.'], 400);
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = str_starts_with($exception->getMessage(), 'Trop de tentatives')
        ? 429
        : $exception->getCode();
    jsonResponse(['message' => $exception->getMessage()], $status >= 400 && $status <= 599 ? $status : 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('agent-cockpit internal error: ' . get_class($exception));
    jsonResponse(['message' => 'Une erreur interne empêche le chargement du cockpit.'], 500);
}
