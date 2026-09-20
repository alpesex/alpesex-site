<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

try {
    /** @var array{config: AlpesEx\Portal\Config, mailer: AlpesEx\Portal\Mail\Mailer} $services */
    $services = require dirname(__DIR__) . '/bootstrap.php';
    $pdo = Database::connect($services['config']);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $directory = dirname(__DIR__) . '/migrations';
    $files = glob($directory . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    $alreadyApplied = $pdo->prepare(
        'SELECT 1 FROM schema_migrations WHERE migration = :migration'
    );
    $recordApplied = $pdo->prepare(
        'INSERT INTO schema_migrations (migration) VALUES (:migration)'
    );

    foreach ($files as $file) {
        $migration = basename($file);
        $alreadyApplied->execute(['migration' => $migration]);

        if ($alreadyApplied->fetchColumn() !== false) {
            fwrite(STDOUT, "Déjà appliquée : {$migration}\n");
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Migration illisible ou vide : {$migration}");
        }

        $pdo->exec($sql);
        $recordApplied->execute(['migration' => $migration]);
        fwrite(STDOUT, "Appliquée : {$migration}\n");
    }

    fwrite(STDOUT, "Migrations MariaDB ALPES'Ex terminées\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Échec des migrations MariaDB ALPES'Ex : {$exception->getMessage()}\n");
    exit(1);
}
