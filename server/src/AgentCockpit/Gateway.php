<?php

declare(strict_types=1);

namespace AlpesEx\Portal\AgentCockpit;

use PDO;
use PDOException;
use RuntimeException;

/** The only write surface offered to the Coordinator MCP connection. */
final class Gateway
{
    public const AGENTS = [
        'coordination', 'satisfaction', 'commercial', 'developpement', 'tests',
        'audiovisuel', 'montage', 'web', 'marketing',
    ];

    public const EVENTS = [
        'prise_en_charge', 'progression', 'transmission', 'blocage',
        'decision_requise', 'validation', 'cloture',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    private static function field(array $data, string $key, int $max, bool $required = true): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            throw new RuntimeException("Champ invalide : {$key}.", 422);
        }
        return trim($value);
    }

    private static function agent(array $data, string $key, bool $required = true): ?string
    {
        $agent = self::field($data, $key, 40, $required);
        if ($agent !== null && !in_array($agent, self::AGENTS, true)) {
            throw new RuntimeException("Agent invalide : {$key}.", 422);
        }
        return $agent;
    }

    public function dossier(array $data): array
    {
        $id = self::field($data, 'dossierId', 64);
        $title = self::field($data, 'title', 190);
        $status = self::field($data, 'status', 40);
        if (!in_array($status, ['open', 'blocked', 'validation', 'closed'], true)) {
            throw new RuntimeException('Statut du dossier invalide.', 422);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO agent_dossiers (id, title, client, version, status, priority, current_agent)
             VALUES (:id, :title, :client, :version, :status, :priority, :agent)
             ON DUPLICATE KEY UPDATE title=VALUES(title), client=VALUES(client),
                version=VALUES(version), status=VALUES(status), priority=VALUES(priority),
                current_agent=VALUES(current_agent), updated_at=CURRENT_TIMESTAMP'
        );
        $priority = self::field($data, 'priority', 20);
        if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) {
            throw new RuntimeException('Priorité invalide.', 422);
        }
        $stmt->execute([
            'id' => $id, 'title' => $title,
            'client' => self::field($data, 'client', 190, false),
            'version' => self::field($data, 'version', 80, false),
            'status' => $status, 'priority' => $priority,
            'agent' => self::agent($data, 'currentAgent', false),
        ]);
        return ['dossierId' => $id, 'status' => $status];
    }

    public function event(array $data): array
    {
        $key = self::field($data, 'eventId', 80);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{5,79}$/D', $key)) {
            throw new RuntimeException('Identifiant d’événement invalide.', 422);
        }
        $dossier = self::field($data, 'dossierId', 64);
        $source = self::agent($data, 'agent');
        $target = self::agent($data, 'destinataire', false);
        if ($source !== 'coordination' && $target !== 'coordination') {
            throw new RuntimeException('Toute transmission passe par le Coordinateur.', 422);
        }
        if ($source === 'coordination' && $target === 'coordination') {
            throw new RuntimeException('Transmission réflexive interdite.', 422);
        }
        $type = self::field($data, 'type', 40);
        if (!in_array($type, self::EVENTS, true)) {
            throw new RuntimeException('Type d’événement invalide.', 422);
        }
        $status = self::field($data, 'statut', 20);
        if (!in_array($status, ['active', 'available', 'blocked'], true)) {
            throw new RuntimeException('Statut d’activité invalide.', 422);
        }
        $summary = self::field($data, 'resume', 500);
        $next = self::field($data, 'prochaineAction', 500);
        $decision = self::field($data, 'decisionLiee', 64, false);
        $deliverables = $data['livrables'] ?? null;
        if (!is_array($deliverables) || count($deliverables) > 20) {
            throw new RuntimeException('Livrables invalides.', 422);
        }
        foreach ($deliverables as $item) {
            if (!is_string($item) || mb_strlen($item) > 500) {
                throw new RuntimeException('Livrable invalide.', 422);
            }
        }
        $payload = [
            'statut' => $status, 'livrables' => array_values($deliverables),
            'decisionLiee' => $decision, 'prochaineAction' => $next,
        ];

        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT dossier_id, source_agent, target_agent, event_type, summary, payload_json FROM agent_events WHERE event_key = :key FOR UPDATE');
            $existing->execute(['key' => $key]);
            $row = $existing->fetch();
            if ($row !== false) {
                $same = $row['dossier_id'] === $dossier && $row['source_agent'] === $source
                    && $row['target_agent'] === $target && $row['event_type'] === $type
                    && $row['summary'] === $summary
                    && json_decode((string) $row['payload_json'], true) === $payload;
                $this->pdo->commit();
                if (!$same) {
                    throw new RuntimeException('Identifiant déjà utilisé avec un contenu différent.', 409);
                }
                return ['eventId' => $key, 'duplicate' => true];
            }
            $exists = $this->pdo->prepare('SELECT status FROM agent_dossiers WHERE id = :id FOR UPDATE');
            $exists->execute(['id' => $dossier]);
            $dossierStatus = $exists->fetchColumn();
            if ($dossierStatus === false) {
                throw new RuntimeException('Dossier inconnu.', 422);
            }
            if ($dossierStatus === 'closed') {
                throw new RuntimeException('Dossier clos.', 409);
            }
            if ($decision !== null) {
                $linked = $this->pdo->prepare('SELECT 1 FROM agent_decisions WHERE id=:decision AND dossier_id=:dossier');
                $linked->execute(['decision' => $decision, 'dossier' => $dossier]);
                if ($linked->fetchColumn() === false) {
                    throw new RuntimeException('Décision liée inconnue pour ce dossier.', 422);
                }
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO agent_events (event_key, dossier_id, source_agent, target_agent, event_type, summary, payload_json)
                 VALUES (:key, :dossier, :source, :target, :type, :summary, :payload)'
            );
            $insert->execute([
                'key' => $key, 'dossier' => $dossier, 'source' => $source,
                'target' => $target, 'type' => $type, 'summary' => $summary,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $activity = $this->pdo->prepare(
                'INSERT INTO agent_activity (dossier_id, agent, status) VALUES (:dossier, :agent, :status)
                 ON DUPLICATE KEY UPDATE status=VALUES(status), updated_at=CURRENT_TIMESTAMP'
            );
            $activity->execute(['dossier' => $dossier, 'agent' => $source, 'status' => $status]);
            $update = $this->pdo->prepare('UPDATE agent_dossiers SET current_agent=:agent, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $update->execute(['agent' => $target ?? $source, 'id' => $dossier]);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            if ($exception->getCode() === '23000') {
                // A concurrent retry won the unique event_key race. The next call can compare it.
                throw new RuntimeException('Événement déjà enregistré ; relancez avec le même identifiant.', 409);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['eventId' => $key, 'duplicate' => false];
    }

    public function decision(array $data): array
    {
        $id = self::field($data, 'decisionId', 64);
        $dossier = self::field($data, 'dossierId', 64);
        $origin = self::agent($data, 'agentOrigine');
        $options = $data['options'] ?? null;
        $impacts = $data['impacts'] ?? null;
        if (!is_array($options) || !array_is_list($options) || count($options) < 2 || count($options) > 3
            || !is_array($impacts)) {
            throw new RuntimeException('Options ou impacts invalides.', 422);
        }
        foreach ($options as $option) {
            if (!is_string($option) || trim($option) === '' || mb_strlen($option) > 300
                || !isset($impacts[$option]) || !is_string($impacts[$option]) || mb_strlen($impacts[$option]) > 500) {
                throw new RuntimeException('Option sans impact valide.', 422);
            }
        }
        $question = self::field($data, 'question', 500);
        $why = self::field($data, 'contexte', 450);
        $recommendation = self::field($data, 'recommandation', 500);
        $blocked = self::field($data, 'travailBloque', 500);
        $urgency = self::field($data, 'urgence', 20);
        if (!in_array($urgency, ['low', 'normal', 'high', 'critical'], true)) {
            throw new RuntimeException('Urgence invalide.', 422);
        }
        $this->pdo->beginTransaction();
        try {
            $dossierQuery = $this->pdo->prepare('SELECT status FROM agent_dossiers WHERE id=:id FOR UPDATE');
            $dossierQuery->execute(['id' => $dossier]);
            $dossierStatus = $dossierQuery->fetchColumn();
            if ($dossierStatus === false || $dossierStatus === 'closed') {
                throw new RuntimeException('Dossier absent ou clos.', 422);
            }
            $existing = $this->pdo->prepare('SELECT dossier_id, requester_agent, question, why_now, options_json, impacts_json,
                recommendation, urgency, blocked_work FROM agent_decisions WHERE id=:id FOR UPDATE');
            $existing->execute(['id' => $id]);
            $previous = $existing->fetch();
            if ($previous !== false) {
                $this->pdo->commit();
                $same = $previous['dossier_id'] === $dossier && $previous['requester_agent'] === 'coordination'
                    && $previous['question'] === $question && $previous['why_now'] === $why . ' [Origine : ' . $origin . ']'
                    && json_decode((string) $previous['options_json'], true) === $options
                    && json_decode((string) $previous['impacts_json'], true) === $impacts
                    && $previous['recommendation'] === $recommendation && $previous['urgency'] === $urgency
                    && $previous['blocked_work'] === $blocked;
                if (!$same) {
                    throw new RuntimeException('Identifiant de décision déjà utilisé avec un contenu différent.', 409);
                }
                return ['decisionId' => $id, 'duplicate' => true];
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO agent_decisions (id, dossier_id, requester_agent, question, why_now, options_json,
                    impacts_json, recommendation, urgency, blocked_work)
                 VALUES (:id, :dossier, :agent, :question, :why, :options, :impacts, :recommendation, :urgency, :blocked)'
            );
            $stmt->execute([
                'id' => $id, 'dossier' => $dossier, 'agent' => 'coordination',
                'question' => $question, 'why' => $why . ' [Origine : ' . $origin . ']',
                'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'impacts' => json_encode($impacts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'recommendation' => $recommendation, 'urgency' => $urgency, 'blocked' => $blocked,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return ['decisionId' => $id, 'duplicate' => false, 'status' => 'pending'];
    }

    public function decisions(array $data): array
    {
        $dossier = self::field($data, 'dossierId', 64);
        $stmt = $this->pdo->prepare(
            'SELECT id AS decisionId, status, answer, decision_note AS note, decided_at AS decidedAt
             FROM agent_decisions WHERE dossier_id=:dossier ORDER BY requested_at, id LIMIT 100'
        );
        $stmt->execute(['dossier' => $dossier]);
        return ['dossierId' => $dossier, 'decisions' => $stmt->fetchAll()];
    }

    public function inputs(array $data): array
    {
        $limit = $data['limit'] ?? 20;
        $includeProcessing = $data['inclureEnCours'] ?? false;
        if (!is_int($limit) || $limit < 1 || $limit > 50) {
            throw new RuntimeException('Limite d’entrées invalide.', 422);
        }
        if (!is_bool($includeProcessing)) {
            throw new RuntimeException('Filtre d’entrées invalide.', 422);
        }
        $where = $includeProcessing ? "status IN ('pending', 'processing')" : "status = 'pending'";
        $stmt = $this->pdo->prepare(
            "SELECT id AS entreeId, source, external_reference AS referenceExterne, title AS titre,
                    summary AS resume, payload_json AS payload, priority AS priorite, status,
                    dossier_id AS dossierId, created_at AS creeeLe, claimed_at AS priseLe
             FROM agent_inputs
             WHERE {$where}
             ORDER BY FIELD(priority, 'critical', 'high', 'normal', 'low'), created_at, id
             LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $inputs = $stmt->fetchAll();
        foreach ($inputs as &$input) {
            $input['payload'] = $input['payload'] === null
                ? null
                : json_decode((string) $input['payload'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($input);
        return ['entrees' => $inputs];
    }

    public function processInput(array $data): array
    {
        $id = self::field($data, 'entreeId', 80);
        $execution = self::field($data, 'executionId', 80);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{5,79}$/D', $execution)) {
            throw new RuntimeException('Identifiant d’exécution invalide.', 422);
        }
        $action = self::field($data, 'action', 30);
        if (!in_array($action, ['prendre', 'terminer', 'rejeter', 'remettre_en_attente'], true)) {
            throw new RuntimeException('Action d’entrée invalide.', 422);
        }
        $dossier = self::field($data, 'dossierId', 64, false);
        $note = self::field($data, 'note', 500, false);

        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT status, claim_key, dossier_id, result_note FROM agent_inputs WHERE id=:id FOR UPDATE');
            $query->execute(['id' => $id]);
            $input = $query->fetch();
            if (!is_array($input)) {
                throw new RuntimeException('Entrée inconnue.', 404);
            }
            if ($dossier !== null) {
                $dossierQuery = $this->pdo->prepare('SELECT 1 FROM agent_dossiers WHERE id=:id');
                $dossierQuery->execute(['id' => $dossier]);
                if ($dossierQuery->fetchColumn() === false) {
                    throw new RuntimeException('Dossier lié inconnu.', 422);
                }
            }

            $current = (string) $input['status'];
            $owner = $input['claim_key'];
            $target = match ($action) {
                'prendre' => 'processing',
                'terminer' => 'completed',
                'rejeter' => 'rejected',
                'remettre_en_attente' => 'pending',
            };
            $allowed = match ($action) {
                'prendre' => $current === 'pending',
                'terminer', 'rejeter', 'remettre_en_attente' => $current === 'processing' && $owner === $execution,
            };
            if (!$allowed) {
                $sameFinal = in_array($target, ['completed', 'rejected'], true)
                    && $current === $target
                    && ($dossier === null || $input['dossier_id'] === $dossier)
                    && ($note === null || $input['result_note'] === $note);
                $sameExecution = $owner === $execution;
                if (($sameFinal && $sameExecution) || ($action === 'prendre' && $current === 'processing' && $sameExecution)) {
                    $this->pdo->commit();
                    return ['entreeId' => $id, 'status' => $current, 'duplicate' => true];
                }
                throw new RuntimeException('Transition d’entrée interdite.', 409);
            }

            $stmt = $this->pdo->prepare(
                'UPDATE agent_inputs SET status=:new_status,
                    claim_key=CASE WHEN :claim_status = \'processing\' THEN :execution
                                   WHEN :reset_status = \'pending\' THEN NULL ELSE claim_key END,
                    dossier_id=COALESCE(:dossier, dossier_id), result_note=:note,
                    claimed_at=CASE WHEN :claim_time_status = \'processing\' THEN COALESCE(claimed_at, CURRENT_TIMESTAMP)
                                    WHEN :reset_time_status = \'pending\' THEN NULL ELSE claimed_at END,
                    completed_at=CASE WHEN :final_status IN (\'completed\', \'rejected\') THEN CURRENT_TIMESTAMP ELSE NULL END
                 WHERE id=:id'
            );
            $stmt->execute([
                'new_status' => $target, 'claim_status' => $target, 'reset_status' => $target,
                'claim_time_status' => $target, 'reset_time_status' => $target, 'final_status' => $target,
                'execution' => $execution,
                'dossier' => $dossier, 'note' => $note, 'id' => $id,
            ]);
            $this->pdo->commit();
            return ['entreeId' => $id, 'status' => $target, 'duplicate' => false];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
