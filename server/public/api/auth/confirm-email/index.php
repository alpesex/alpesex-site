<?php

declare(strict_types=1);

use AlpesEx\Portal\Auth\ConfirmEmail;
use AlpesEx\Portal\Database;

$appUrl = 'https://alpes-ex.fr';

try {
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    /** @var array{config: AlpesEx\Portal\Config, mailer: AlpesEx\Portal\Mail\Mailer} $services */
    $services = require $appDirectory . '/bootstrap.php';
    $appUrl = rtrim($services['config']->string('APP_URL'), '/');

    (new ConfirmEmail(Database::connect($services['config'])))->execute(
        trim((string) ($_GET['token'] ?? ''))
    );

    header('Location: ' . $appUrl . '/compte/?confirmation=success', true, 303);
} catch (Throwable) {
    header('Location: ' . $appUrl . '/compte/?confirmation=invalid', true, 303);
}
