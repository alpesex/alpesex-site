<?php

declare(strict_types=1);

use AlpesEx\Portal\Config;
use AlpesEx\Portal\Mail\Mailer;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

$privateDirectory = getenv('ALPESEX_PRIVATE_DIR') ?: '/home/www/private';

Dotenv::createImmutable($privateDirectory)->load();

date_default_timezone_set('Europe/Paris');

$config = new Config($_ENV);

return [
    'config' => $config,
    'mailer' => new Mailer($config),
];
