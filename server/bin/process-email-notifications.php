<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\Mail\NotificationQueue;
use AlpesEx\Portal\Mail\TransactionalMailer;

$appDirectory = dirname(__DIR__);
$services = require $appDirectory . '/bootstrap.php';
$pdo = Database::connect($services['config']);
$queue = new NotificationQueue($pdo, new TransactionalMailer($services['mailer']));

$processed = 0;
$limit = 50;
while ($processed < $limit && $queue->processNext()) {
    $processed++;
}

echo "Notifications e-mail traitées : {$processed}" . PHP_EOL;
