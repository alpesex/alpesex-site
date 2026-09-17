<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

$appDirectory = dirname(__DIR__);
$services = require $appDirectory . '/bootstrap.php';
$pdo = Database::connect($services['config']);

$auth = $pdo->exec(
    "DELETE FROM auth_tokens
     WHERE (expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)
        OR (used_at IS NOT NULL AND used_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)"
);
$downloads = $pdo->exec(
    "DELETE FROM secure_download_links
     WHERE (expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)
        OR (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)"
);
$invitations = $pdo->exec(
    "DELETE FROM organization_invitations
     WHERE (expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)
        OR (accepted_at IS NOT NULL AND accepted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)
        OR (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY)"
);
$emailLogs = $pdo->exec(
    "DELETE FROM email_delivery_logs
     WHERE created_at < UTC_TIMESTAMP() - INTERVAL 180 DAY"
);

echo 'Liens expirés supprimés : '
    . ((int) $auth + (int) $downloads + (int) $invitations)
    . PHP_EOL;
echo 'Journaux e-mail expirés supprimés : ' . (int) $emailLogs . PHP_EOL;
