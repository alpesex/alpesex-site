<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

$appDirectory = dirname(__DIR__);
$services = require $appDirectory . '/bootstrap.php';
$pdo = Database::connect($services['config']);

$summary = $pdo->query(
    "SELECT delivery_status, COUNT(*) AS total
     FROM email_delivery_logs
     WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
     GROUP BY delivery_status
     ORDER BY delivery_status"
)->fetchAll();

echo "Journal e-mail ALPES'Ex sur les dernières 24 heures" . PHP_EOL;
if ($summary === []) {
    echo "Aucun envoi journalisé." . PHP_EOL;
    exit(0);
}

foreach ($summary as $row) {
    echo (string) $row['delivery_status'] . ' : ' . (int) $row['total'] . PHP_EOL;
}
