<?php

declare(strict_types=1);

use AlpesEx\Portal\Config;

$services = require dirname(__DIR__) . '/bootstrap.php';

/** @var Config $config */
$config = $services['config'];

$required = [
    'SMTP_HOST',
    'SMTP_PORT',
    'SMTP_ENCRYPTION',
    'SMTP_USERNAME',
    'SMTP_PASSWORD',
    'MAIL_FROM_ADDRESS',
    'MAIL_FROM_NAME',
    'MAIL_REPLY_TO_ADDRESS',
    'MAIL_REPLY_TO_NAME',
];

foreach ($required as $key) {
    if ($key === 'SMTP_PORT') {
        $config->integer($key);
    } elseif (str_ends_with($key, '_ADDRESS') || $key === 'SMTP_USERNAME') {
        $config->email($key);
    } else {
        $config->string($key);
    }
}

fwrite(STDOUT, "Configuration SMTP ALPES'Ex valide.\n");
