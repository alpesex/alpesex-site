<?php

declare(strict_types=1);

use AlpesEx\Portal\Licensing\LicenseIssuer;

try {
    $services = require dirname(__DIR__) . '/bootstrap.php';
    $issuer = new LicenseIssuer($services['config']);
    $test = $issuer->issueMaster(
        'master-self-test',
        'org-self-test',
        'Contrôle interne',
        'self-test',
        new DateTimeImmutable('+5 minutes')
    );
    if (!str_contains($test['token'], '.')) {
        throw new RuntimeException('Jeton de contrôle invalide.');
    }
    fwrite(STDOUT, "Autorité de licences prête : " . LicenseIssuer::AUTHORITY_ID . "\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Autorité de licences indisponible : {$exception->getMessage()}\n");
    exit(1);
}
