<?php

declare(strict_types=1);

use AlpesEx\Portal\AgentCockpit\Gateway;
use AlpesEx\Portal\AgentCockpit\OAuth;

require dirname(__DIR__) . '/src/AgentCockpit/Gateway.php';
require dirname(__DIR__) . '/src/AgentCockpit/OAuth.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $operation, int $code): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        check($exception->getCode() === $code, 'Mauvais code de refus.');
        return;
    }
    throw new RuntimeException('Action invalide acceptée.');
}

$pdo = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
    (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
foreach (['016_create_agent_cockpit.sql', '017_agent_coordinator_gateway.sql'] as $migration) {
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/' . $migration);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
}
$pdo->exec('CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, email VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL)');
$pdo->exec("INSERT INTO users VALUES (1, 'alpes.ex.asm@gmail.com', 'active'), (2, 'client@example.test', 'active')");
$_ENV['ALPESEX_ADMIN_EMAILS'] = 'alpes.ex.asm@gmail.com';
$_ENV['ALPESEX_MCP_ENABLED'] = '1';
$_ENV['ALPESEX_MCP_REDIRECT_URIS'] = 'https://chatgpt.com/connector_platform_oauth_redirect';

$gateway = new Gateway($pdo);
$gateway->dossier(['dossierId' => 'TEST-COCKPIT-9-AGENTS', 'title' => 'Fictif', 'status' => 'open', 'priority' => 'normal']);
$event = [
    'eventId' => 'test:coordination:0001', 'dossierId' => 'TEST-COCKPIT-9-AGENTS',
    'agent' => 'satisfaction', 'destinataire' => 'coordination', 'type' => 'transmission',
    'statut' => 'available', 'resume' => 'Demande fictive qualifiée',
    'livrables' => ['Note fictive'], 'prochaineAction' => 'Transmettre au Commercial',
];
check($gateway->event($event)['duplicate'] === false, 'Premier événement non enregistré.');
check($gateway->event($event)['duplicate'] === true, 'Reprise non dédupliquée.');
check((int) $pdo->query('SELECT COUNT(*) FROM agent_events')->fetchColumn() === 1, 'Événement doublé.');
$different = $event;
$different['resume'] = 'Autre contenu';
rejects(static fn () => $gateway->event($different), 409);
$direct = $event;
$direct['eventId'] = 'test:satisfaction:0002';
$direct['destinataire'] = 'commercial';
rejects(static fn () => $gateway->event($direct), 422);
$active = $event;
$active['eventId'] = 'test:coordination:0002';
$active['agent'] = 'coordination';
$active['destinataire'] = 'commercial';
$active['statut'] = 'active';
$gateway->event($active);
check($pdo->query("SELECT status FROM agent_activity WHERE agent='coordination'")->fetchColumn() === 'active', 'Statut agent absent.');

$decision = [
    'decisionId' => 'TEST-DECISION-0001', 'dossierId' => 'TEST-COCKPIT-9-AGENTS',
    'agentOrigine' => 'commercial', 'question' => 'Continuer la simulation ?',
    'contexte' => 'Recette fictive', 'options' => ['Continuer', 'Arrêter'],
    'impacts' => ['Continuer' => 'Simulation seulement', 'Arrêter' => 'Arrêt du test'],
    'recommandation' => 'Continuer', 'urgence' => 'normal', 'travailBloque' => 'Étape suivante',
];
check($gateway->decision($decision)['duplicate'] === false, 'Décision non créée.');
check($gateway->decision($decision)['duplicate'] === true, 'Décision doublée.');
check($gateway->decisions(['dossierId' => 'TEST-COCKPIT-9-AGENTS'])['decisions'][0]['status'] === 'pending', 'Silence traité comme validation.');

$oauth = new OAuth($pdo);
check($oauth->activeAdmin(1) && !$oauth->activeAdmin(2), 'Liste administrateur non appliquée.');
$verifier = str_repeat('v', 43);
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$request = [
    'client_id' => OAuth::CLIENT_ID,
    'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
    'response_type' => 'code', 'resource' => OAuth::RESOURCE,
    'code_challenge_method' => 'S256', 'code_challenge' => $challenge,
    'scope' => OAuth::SCOPE, 'state' => 'random-state-1234',
];
rejects(static fn () => $oauth->issueCode(2, $request), 403);
$code = $oauth->issueCode(1, $request);
$exchange = [
    'grant_type' => 'authorization_code', 'client_id' => OAuth::CLIENT_ID,
    'redirect_uri' => $request['redirect_uri'], 'resource' => OAuth::RESOURCE,
    'code' => $code, 'code_verifier' => $verifier,
];
$bad = $exchange;
$bad['code_verifier'] = str_repeat('x', 43);
rejects(static fn () => $oauth->exchange($bad), 400);
$token = $oauth->exchange($exchange)['access_token'];
check($oauth->authorizeBearer('Bearer ' . $token), 'Jeton valide refusé.');
rejects(static fn () => $oauth->exchange($exchange), 400);
$pdo->exec('UPDATE agent_oauth_tokens SET revoked_at=UTC_TIMESTAMP()');
check(!$oauth->authorizeBearer('Bearer ' . $token), 'Jeton révoqué accepté.');

echo "Cockpit gateway integration: OK\n";
