<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Auth;

use AlpesEx\Portal\Config;
use AlpesEx\Portal\Mail\Mailer;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class InviteMember
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Mailer $mailer
    ) {
    }

    /** @param array<string, mixed> $input */
    public function execute(int $organizationId, int $managerId, array $input): void
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $firstName = trim((string) ($input['firstName'] ?? ''));
        $lastName = trim((string) ($input['lastName'] ?? ''));
        $license = (string) ($input['licenseType'] ?? 'user');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Adresse e-mail invalide.');
        }
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new RuntimeException('Le prénom ou le nom est trop long.');
        }
        if (!in_array($license, ['user', 'manager', 'direction'], true)) {
            throw new RuntimeException('Type de licence invalide.');
        }

        $existingUser = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existingUser->execute(['email' => $email]);
        if ($existingUser->fetch()) {
            throw new RuntimeException('Cette adresse e-mail possède déjà un compte.');
        }

        $pending = $this->pdo->prepare(
            'SELECT id FROM organization_invitations
             WHERE organization_id = :organization_id AND email = :email
               AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $pending->execute(['organization_id' => $organizationId, 'email' => $email]);
        if ($pending->fetch()) {
            throw new RuntimeException('Une invitation valide est déjà en attente pour cette adresse.');
        }

        $token = bin2hex(random_bytes(32));
        $statement = $this->pdo->prepare(
            'INSERT INTO organization_invitations
             (organization_id, invited_by_user_id, email, first_name, last_name, intended_license, token_hash, expires_at)
             VALUES (:organization_id, :invited_by_user_id, :email, :first_name, :last_name, :intended_license, :token_hash, :expires_at)'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'invited_by_user_id' => $managerId,
            'email' => $email,
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'intended_license' => $license,
            'token_hash' => hash('sha256', $token),
            'expires_at' => (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s'),
        ]);

        $url = rtrim($this->config->string('APP_URL'), '/') . '/compte/?invitation=' . rawurlencode($token);
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = $firstName !== '' ? $firstName : 'Bonjour';
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->mailer->send(
            $email,
            "Invitation à rejoindre une équipe ALPES'Ex",
            "<p>{$safeName},</p><p>Le gestionnaire de votre organisation vous invite à rejoindre son espace ALPES'Ex.</p><p><a href=\"{$safeUrl}\">Créer mon compte</a></p><p>Ce lien personnel est valable pendant 7 jours.</p>",
            "{$name},\n\nCréez votre compte ALPES'Ex : {$url}\n\nCe lien personnel est valable pendant 7 jours."
        );
    }
}
