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

$agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$mobile = preg_match('/Android|iPhone|iPad|iPod|Mobile|Tablet/i', $agent) === 1;
if (!$mobile) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    http_response_code(403);
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>CPMP ASM — Application Windows requise</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f3f1ec;color:#172127;font-family:Segoe UI,Arial,sans-serif}.box{max-width:620px;margin:20px;padding:34px;border-radius:18px;background:#fff;box-shadow:0 18px 50px #11181d20}h1{margin-top:0}a{display:inline-block;margin-top:14px;padding:12px 18px;border-radius:999px;background:#d66b2c;color:#fff;text-decoration:none;font-weight:800}</style>'
        . '<main class="box"><h1>Utilisez l’application Windows</h1><p>Sur PC, CPMP ASM est disponible exclusivement au format .exe depuis votre espace client.</p><a href="/mon-compte/#downloads">Télécharger CPMP ASM pour Windows</a></main></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
readfile(__DIR__ . '/index.html');
