<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

try {
    /** @var array{config: AlpesEx\Portal\Config, mailer: AlpesEx\Portal\Mail\Mailer} $services */
    $services = require dirname(__DIR__) . '/bootstrap.php';

    $pdo = Database::connect($services['config']);
    $pdo->query('SELECT 1')->fetchColumn();

    fwrite(STDOUT, "Connexion MariaDB ALPES'Ex valide\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Connexion MariaDB ALPES'Ex invalide : {$exception->getMessage()}\n");
    exit(1);
}
