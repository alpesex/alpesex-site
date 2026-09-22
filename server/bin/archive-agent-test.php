<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

$id = 'TEST-COCKPIT-9-AGENTS';
if (($argv[1] ?? '') !== $id) {
    fwrite(STDERR, "Identifiant de test exact requis.\n");
    exit(1);
}
try {
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__);
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    $pdo->beginTransaction();
    $decision = $pdo->prepare("UPDATE agent_decisions SET status='cancelled' WHERE dossier_id=:id AND status='pending'");
    $decision->execute(['id' => $id]);
    $activity = $pdo->prepare("UPDATE agent_activity SET status='available' WHERE dossier_id=:id");
    $activity->execute(['id' => $id]);
    $dossier = $pdo->prepare("UPDATE agent_dossiers SET status='closed', current_agent='coordination' WHERE id=:id");
    $dossier->execute(['id' => $id]);
    $pdo->commit();
    echo "Dossier fictif archivé ; événements conservés pour audit.\n";
} catch (Throwable) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Archivage interrompu.\n");
    exit(1);
}
