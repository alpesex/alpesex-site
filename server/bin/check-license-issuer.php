<?php

declare(strict_types=1);

use AlpesEx\Portal\Licensing\LicenseIssuer;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    (new LicenseIssuer())->issueUser([
        'id' => 'configuration-check',
        'masterId' => 'configuration-master',
        'organizationId' => 'configuration-organization',
        'deploymentId' => 'configuration-deployment',
        'email' => 'configuration-check@alpes-ex.fr',
    ]);
    fwrite(STDOUT, "Configuration de signature des licences ALPES'Ex valide\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Configuration de signature des licences ALPES'Ex invalide : {$exception->getMessage()}\n");
    exit(1);
}
