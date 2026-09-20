<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\Mail\MarketingUnsubscribe;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$title = 'Désinscription confirmée';
$message = 'Vous ne recevrez plus les communications promotionnelles d’ALPES’Ex.';

try {
    $token = (string) ($_GET['token'] ?? '');
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    (new MarketingUnsubscribe($pdo))->execute($token);
} catch (Throwable) {
    http_response_code(422);
    $title = 'Lien non valide';
    $message = 'Ce lien de désinscription est invalide, expiré ou a déjà été utilisé.';
}

$safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>' . $safeTitle . " – ALPES'Ex</title>"
    . '<style>body{margin:0;background:#f4f7fb;color:#172033;font-family:Arial,sans-serif}'
    . 'main{max-width:620px;margin:10vh auto;padding:40px;background:#fff;border-radius:18px;box-shadow:0 16px 50px #12213a18}'
    . 'h1{color:#173a63}a{color:#d96727;font-weight:700}</style></head><body><main>'
    . '<h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>'
    . '<p><a href="../../../">Retour au site ALPES’Ex</a></p></main></body></html>';
