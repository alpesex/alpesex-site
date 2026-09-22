<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

if (($argv[1] ?? '') !== 'revoke-all') {
    fwrite(STDERR, "Argument explicite revoke-all requis.\n");
    exit(1);
}
try {
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__);
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    $pdo->exec('UPDATE agent_oauth_tokens SET revoked_at=UTC_TIMESTAMP() WHERE revoked_at IS NULL');
    echo "Accès OAuth du Coordinateur révoqués.\n";
} catch (Throwable) {
    fwrite(STDERR, "Révocation interrompue.\n");
    exit(1);
}
