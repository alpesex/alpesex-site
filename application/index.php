<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

session_name('ALPESEXSESSID');
session_set_cookie_params([
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$userId) {
    header('Cache-Control: private, no-store, max-age=0');
    header('Location: /compte/?session=expired', true, 302);
    exit;
}

try {
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 2) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    $access = $pdo->prepare(
        "SELECT 1 FROM users u INNER JOIN organizations o ON o.id=u.organization_id
         WHERE u.id=:id AND u.status='active' AND o.status='active' LIMIT 1"
    );
    $access->execute(['id' => $userId]);
    if ($access->fetchColumn() === false) {
        throw new RuntimeException('Compte inactif');
    }
} catch (Throwable) {
    session_destroy();
    header('Cache-Control: private, no-store, max-age=0');
    header('Location: /compte/?session=expired', true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
readfile(__DIR__ . '/index.html');
