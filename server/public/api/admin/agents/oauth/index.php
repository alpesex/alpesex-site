<?php

declare(strict_types=1);

use AlpesEx\Portal\AgentCockpit\OAuth;
use AlpesEx\Portal\Database;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function oauthError(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 5) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $oauth = new OAuth(Database::connect($services['config']));
    if (!OAuth::enabled()) {
        oauthError('service_unavailable', 503);
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $flow = $_GET['flow'] ?? '';
    if ($flow === 'token') {
        if ($method !== 'POST' || !str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded')) {
            oauthError('invalid_request', 400);
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
            oauthError('invalid_request', 413);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($oauth->exchange($_POST), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if ($flow !== 'authorize' || !in_array($method, ['GET', 'POST'], true)) {
        oauthError('invalid_request', 400);
    }
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $params = $method === 'POST' ? $_POST : $_GET;
    OAuth::validateAuthorization($params);
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId === null || !$oauth->activeAdmin((int) $userId)) {
        header('Content-Type: text/html; charset=utf-8');
        $self = htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/api/admin/agents/oauth/?flow=authorize'), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Connexion ALPES’Ex</title>';
        echo '<p>Connectez-vous au compte administrateur ALPES’Ex dans un autre onglet, puis revenez ici.</p>';
        echo '<p><a href="/compte/">Ouvrir la connexion ALPES’Ex</a></p>';
        echo '<a href="' . $self . '">Réessayer l’autorisation</a></html>';
        exit;
    }
    $_SESSION['agent_oauth_csrf'] ??= bin2hex(random_bytes(32));
    if ($method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Autoriser le Coordinateur</title>';
        echo '<h1>Autoriser le Coordinateur ALPES’Ex</h1>';
        echo '<p>Cette connexion peut écrire les dossiers et événements du cockpit et lire les décisions. Elle ne peut pas répondre à votre place.</p>';
        echo '<form method="post" action="?flow=authorize">';
        foreach (['client_id', 'redirect_uri', 'response_type', 'resource', 'code_challenge', 'code_challenge_method', 'scope', 'state'] as $key) {
            echo '<input type="hidden" name="' . $key . '" value="' . htmlspecialchars((string) $params[$key], ENT_QUOTES, 'UTF-8') . '">';
        }
        echo '<input type="hidden" name="csrf" value="' . $_SESSION['agent_oauth_csrf'] . '">';
        echo '<button type="submit">Autoriser</button></form></html>';
        exit;
    }
    if (!hash_equals((string) $_SESSION['agent_oauth_csrf'], (string) ($params['csrf'] ?? ''))) {
        oauthError('invalid_request', 403);
    }
    unset($_SESSION['agent_oauth_csrf']);
    $code = $oauth->issueCode((int) $userId, $params);
    $redirect = $params['redirect_uri'] . (str_contains($params['redirect_uri'], '?') ? '&' : '?') . http_build_query([
        'code' => $code, 'state' => $params['state'], 'iss' => OAuth::ISSUER,
    ], '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $redirect, true, 303);
    exit;
} catch (RuntimeException $exception) {
    oauthError('invalid_request', $exception->getCode() >= 400 && $exception->getCode() <= 599 ? $exception->getCode() : 400);
} catch (Throwable) {
    oauthError('server_error', 500);
}
