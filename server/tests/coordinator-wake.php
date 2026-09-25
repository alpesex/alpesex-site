<?php

declare(strict_types=1);

use AlpesEx\Portal\AgentCockpit\CoordinatorWake;

require dirname(__DIR__) . '/src/AgentCockpit/CoordinatorWake.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sent = [];
$sender = static function (string $recipient) use (&$sent): void {
    $sent[] = $recipient;
};

$disabled = new CoordinatorWake([
    'ALPESEX_COORDINATOR_WAKE_ENABLED' => '0',
    'ALPESEX_ADMIN_EMAILS' => 'alpes.ex.asm@gmail.com',
], $sender);
check($disabled->notify() === false, 'Un réveil désactivé ne doit pas envoyer d’e-mail.');
check($sent === [], 'Aucun destinataire ne doit être appelé lorsque le réveil est désactivé.');

$enabled = new CoordinatorWake([
    'ALPESEX_COORDINATOR_WAKE_ENABLED' => '1',
    'ALPESEX_ADMIN_EMAILS' => ' alpes.ex.asm@gmail.com , second@example.test ',
], $sender);
check($enabled->notify() === true, 'Le réveil activé doit envoyer un e-mail.');
check($sent === ['alpes.ex.asm@gmail.com'], 'Le premier administrateur valide doit être destinataire.');

$explicit = new CoordinatorWake([
    'ALPESEX_COORDINATOR_WAKE_ENABLED' => '1',
    'ALPESEX_COORDINATOR_WAKE_EMAIL' => 'Coordinator@example.test',
    'ALPESEX_ADMIN_EMAILS' => 'alpes.ex.asm@gmail.com',
], $sender);
check($explicit->notify() === true, 'Le destinataire explicite doit être accepté.');
check($sent[1] === 'coordinator@example.test', 'Le destinataire explicite doit être normalisé.');

$invalid = new CoordinatorWake([
    'ALPESEX_COORDINATOR_WAKE_ENABLED' => '1',
    'ALPESEX_COORDINATOR_WAKE_EMAIL' => 'adresse-invalide',
    'ALPESEX_ADMIN_EMAILS' => 'aussi-invalide',
], $sender);
check($invalid->notify() === false, 'Une configuration sans adresse valide ne doit rien envoyer.');
check(count($sent) === 2, 'Une adresse invalide ne doit jamais atteindre le transport e-mail.');

echo "Coordinator wake tests: OK\n";
