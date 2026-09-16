<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Auth;

use AlpesEx\Portal\Config;
use AlpesEx\Portal\Mail\Mailer;
use AlpesEx\Portal\Mail\TransactionalMailer;
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
    public function execute(int $organizationId, int $managerId, array $input): array
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

        $existingUser = $this->pdo->prepare(
            'SELECT id, organization_id, status FROM users WHERE email = :email LIMIT 1'
        );
        $existingUser->execute(['email' => $email]);
        $user = $existingUser->fetch();

        $revokePending = $this->pdo->prepare(
            'UPDATE organization_invitations
             SET revoked_at = UTC_TIMESTAMP()
             WHERE organization_id = :organization_id AND email = :email
               AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $revokePending->execute(['organization_id' => $organizationId, 'email' => $email]);

        if (is_array($user)) {
            $sameOrganization = (int) $user['organization_id'] === $organizationId;
            $reactivated = false;
            if ($sameOrganization && $user['status'] === 'removed') {
                $reactivate = $this->pdo->prepare(
                    "UPDATE users SET status='active' WHERE id=:id AND organization_id=:organization_id AND status='removed'"
                );
                $reactivate->execute([
                    'id' => $user['id'],
                    'organization_id' => $organizationId,
                ]);
                $reactivated = $reactivate->rowCount() === 1;
            }
            $url = rtrim($this->config->string('APP_URL'), '/') . '/compte/?invitationAccount=existing';
            (new TransactionalMailer($this->mailer))->teamInvitation(
                $email,
                $firstName,
                $url,
                true
            );

            return [
                'existingAccount' => true,
                'sameOrganization' => $sameOrganization,
                'reactivated' => $reactivated,
            ];
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
        (new TransactionalMailer($this->mailer))->teamInvitation(
            $email,
            $firstName,
            $url,
            false
        );

        return [
            'existingAccount' => false,
            'sameOrganization' => false,
            'reactivated' => false,
        ];
    }
}
