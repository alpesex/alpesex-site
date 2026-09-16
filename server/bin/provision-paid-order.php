<?php

declare(strict_types=1);

use AlpesEx\Portal\Commerce\PaidOrderLicenseProvisioner;
use AlpesEx\Portal\Database;
use AlpesEx\Portal\Licensing\LicenseIssuer;
use AlpesEx\Portal\Mail\TransactionalMailer;

try {
    if ($argc !== 4) {
        throw new RuntimeException('Usage : php bin/provision-paid-order.php <commande> <facture> <url-facture>');
    }
    $services = require dirname(__DIR__) . '/bootstrap.php';
    $provisioner = new PaidOrderLicenseProvisioner(
        Database::connect($services['config']),
        new LicenseIssuer($services['config']),
        new TransactionalMailer($services['mailer'])
    );
    $result = $provisioner->provision($argv[1], $argv[2], $argv[3], new DateTimeImmutable('now'));
    fwrite(STDOUT, $result['alreadyProvisioned'] ? "Commande déjà provisionnée.\n" : "Licences générées et envoyées.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Échec du provisionnement : {$exception->getMessage()}\n");
    exit(1);
}
