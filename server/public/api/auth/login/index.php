<?php

declare(strict_types=1);

use AlpesEx\Portal\Auth\Login;
use AlpesEx\Portal\Database;
use AlpesEx\Portal\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input') ?: '', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('Corps de requête invalide.');
    }

    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    /** @var array{config: AlpesEx\Portal\Config, mailer: AlpesEx\Portal\Mail\Mailer} $services */
    $services = require $appDirectory . '/bootstrap.php';

    $pdo = Database::connect($services['config']);
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    (new RateLimiter($pdo))->assertAllowed(
        'login',
        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $email,
        10,
        3600
    );

    $user = (new Login($pdo))->execute($input);
    $remember = in_array($input['remember'] ?? null, [true, 1, '1', 'on', 'true'], true);
    $lifetime = $remember ? 2592000 : 0;

    session_name('ALPESEXSESSID');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['organization_id'] = $user['organizationId'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['first_name'] = $user['firstName'];
    $_SESSION['last_name'] = $user['lastName'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['authenticated_at'] = time();
    if ($remember) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + $lifetime,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    echo json_encode([
        'message' => 'Connexion réussie. Bienvenue ' . $user['firstName'] . '.',
        'user' => [
            'firstName' => $user['firstName'],
            'lastName' => $user['lastName'],
            'role' => $user['role'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['message' => 'Données JSON invalides.'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(str_starts_with($exception->getMessage(), 'Trop de tentatives') ? 429 : 401);
    echo json_encode(['message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    $logDirectory = dirname(__DIR__, 4) . '/private';
    if (is_dir($logDirectory) && is_writable($logDirectory)) {
        error_log(
            gmdate('c') . ' login ' . get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL,
            3,
            $logDirectory . '/api-errors.log'
        );
    }
    http_response_code(500);
    echo json_encode(['message' => 'Une erreur interne empêche la connexion.'], JSON_UNESCAPED_UNICODE);
}
