<?php

declare(strict_types=1);

use AlpesEx\Portal\AgentCockpit\Gateway;
use AlpesEx\Portal\AgentCockpit\OAuth;
use AlpesEx\Portal\Database;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function rpc(int|string|null $id, array $payload): never
{
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function rpcError(int|string|null $id, int $code, string $message): never
{
    rpc($id, ['error' => ['code' => $code, 'message' => $message]]);
}

function tool(string $name, string $description, array $properties, array $required, bool $readOnly): array
{
    return [
        'name' => $name, 'title' => str_replace('_', ' ', ucfirst($name)),
        'description' => $description,
        'inputSchema' => [
            'type' => 'object', 'properties' => $properties,
            'required' => $required, 'additionalProperties' => false,
        ],
        'annotations' => [
            'readOnlyHint' => $readOnly, 'destructiveHint' => false,
            'openWorldHint' => false, 'idempotentHint' => true,
        ],
    ];
}

function stringProperty(string $description): array
{
    return ['type' => 'string', 'description' => $description];
}

function tools(): array
{
    $agent = ['type' => 'string', 'enum' => Gateway::AGENTS];
    return [
        tool('dossier_upsert', 'Use this when the Coordinator opens or updates one dossier. A closed dossier never implies approval for another action.', [
            'dossierId' => stringProperty('Stable dossier identifier, at most 64 characters.'),
            'title' => stringProperty('Dossier title.'),
            'client' => stringProperty('Fictional or authorized client label.'),
            'version' => stringProperty('Version or release label.'),
            'status' => ['type' => 'string', 'enum' => ['open', 'blocked', 'validation', 'closed']],
            'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'critical']],
            'currentAgent' => $agent,
        ], ['dossierId', 'title', 'status', 'priority'], false),
        tool('enregistrer_evenement', 'Use this only as Coordinator to record one agent event. Business agents send their results to the Coordinator first. Reuse the same eventId on retry.', [
            'eventId' => stringProperty('Stable idempotency key; reuse only for the same event.'),
            'dossierId' => stringProperty('Existing dossier identifier.'),
            'agent' => $agent,
            'type' => ['type' => 'string', 'enum' => Gateway::EVENTS],
            'statut' => ['type' => 'string', 'enum' => ['active', 'available', 'blocked']],
            'resume' => stringProperty('What actually happened, no secrets.'),
            'destinataire' => $agent,
            'livrables' => ['type' => 'array', 'items' => stringProperty('Authorized artifact reference or fictional label.'), 'maxItems' => 20],
            'decisionLiee' => stringProperty('Related decision identifier, if any.'),
            'prochaineAction' => stringProperty('Next action or Aucune.'),
        ], ['eventId', 'dossierId', 'agent', 'type', 'statut', 'resume', 'livrables', 'prochaineAction'], false),
        tool('demander_decision', 'Use this only as Coordinator to submit a decision for Lucas with two or three options. Never interpret silence as approval.', [
            'decisionId' => stringProperty('Stable decision identifier.'),
            'dossierId' => stringProperty('Existing dossier identifier.'),
            'agentOrigine' => $agent,
            'question' => stringProperty('Decision question.'),
            'contexte' => stringProperty('Why the decision is needed.'),
            'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2, 'maxItems' => 3],
            'impacts' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'recommandation' => stringProperty('Coordinator recommendation.'),
            'urgence' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'critical']],
            'travailBloque' => stringProperty('Work held until Lucas answers.'),
        ], ['decisionId', 'dossierId', 'agentOrigine', 'question', 'contexte', 'options', 'impacts', 'recommandation', 'urgence', 'travailBloque'], false),
        tool('lire_decisions', 'Use this when the Coordinator needs the current decision state for one dossier. A pending state never means approval.', [
            'dossierId' => stringProperty('Existing dossier identifier.'),
        ], ['dossierId'], true),
        tool('lire_entrees', 'Use this only as Coordinator to inspect pending or already claimed inputs. Reading never approves or completes an input.', [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            'inclureEnCours' => ['type' => 'boolean', 'default' => false, 'description' => 'Include inputs already claimed by a run.'],
        ], [], true),
        tool('traiter_entree', 'Use this only as Coordinator to claim, complete, reject or requeue one input. Claim before starting work.', [
            'entreeId' => stringProperty('Stable input identifier.'),
            'executionId' => stringProperty('Stable identifier for this scheduled or interactive run.'),
            'action' => ['type' => 'string', 'enum' => ['prendre', 'terminer', 'rejeter', 'remettre_en_attente']],
            'dossierId' => stringProperty('Dossier created or linked for this input.'),
            'note' => stringProperty('Short processing result or reason.'),
        ], ['entreeId', 'executionId', 'action'], false),
    ];
}

try {
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 5) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    $oauth = new OAuth($pdo);
    if (!$oauth->authorizeBearer((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer resource_metadata="https://alpes-ex.fr/.well-known/oauth-protected-resource", scope="agent_cockpit:coordinate"');
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed']);
        exit;
    }
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 1 || $length > 65536) {
        http_response_code(413);
        echo json_encode(['error' => 'invalid_request_size']);
        exit;
    }
    $request = json_decode(file_get_contents('php://input') ?: '', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($request) || ($request['jsonrpc'] ?? null) !== '2.0'
        || !is_string($request['method'] ?? null)) {
        rpcError(null, -32600, 'Invalid Request');
    }
    $id = $request['id'] ?? null;
    if ($id !== null && !is_int($id) && (!is_string($id) || strlen($id) > 100)) {
        rpcError(null, -32600, 'Invalid Request');
    }
    $params = $request['params'] ?? [];
    if (!is_array($params)) {
        rpcError($id, -32602, 'Invalid params');
    }
    if ($request['method'] === 'notifications/initialized') {
        http_response_code(202);
        exit;
    }
    if ($request['method'] === 'initialize') {
        $requestedVersion = $params['protocolVersion'] ?? null;
        $version = in_array($requestedVersion, ['2025-03-26', '2025-06-18', '2025-11-25'], true)
            ? $requestedVersion : '2025-11-25';
        rpc($id, ['result' => [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'alpesex-coordinateur', 'version' => '1.1.0'],
            'instructions' => 'Outils réservés au Coordinateur. Les agents métier transmettent leurs résultats au Coordinateur ; ne leur déléguez jamais ces outils. Aucun silence de Lucas ne vaut approbation.',
        ]]);
    }
    if ($request['method'] === 'ping') {
        rpc($id, ['result' => new stdClass()]);
    }
    if ($request['method'] === 'tools/list') {
        rpc($id, ['result' => ['tools' => tools()]]);
    }
    if ($request['method'] !== 'tools/call') {
        rpcError($id, -32601, 'Method not found');
    }
    $name = $params['name'] ?? null;
    $arguments = $params['arguments'] ?? null;
    if (!is_string($name) || !is_array($arguments)) {
        rpcError($id, -32602, 'Invalid params');
    }
    $gateway = new Gateway($pdo);
    $result = match ($name) {
        'dossier_upsert' => $gateway->dossier($arguments),
        'enregistrer_evenement' => $gateway->event($arguments),
        'demander_decision' => $gateway->decision($arguments),
        'lire_decisions' => $gateway->decisions($arguments),
        'lire_entrees' => $gateway->inputs($arguments),
        'traiter_entree' => $gateway->processInput($arguments),
        default => null,
    };
    if ($result === null) {
        rpcError($id, -32601, 'Tool not found');
    }
    rpc($id, ['result' => ['structuredContent' => $result, 'content' => [[
        'type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]]]]);
} catch (JsonException) {
    rpcError(null, -32700, 'Parse error');
} catch (RuntimeException $exception) {
    rpcError(isset($id) && (is_int($id) || is_string($id)) ? $id : null, -32602, is_int($exception->getCode()) && $exception->getCode() >= 400 && $exception->getCode() <= 499 ? $exception->getMessage() : 'Invalid params');
} catch (Throwable) {
    http_response_code(500);
    rpcError(isset($id) && (is_int($id) || is_string($id)) ? $id : null, -32603, 'Internal error');
}
