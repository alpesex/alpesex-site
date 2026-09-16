<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Auth;

use PDO;
use RuntimeException;

final class RegisterInvitedUser
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): void
    {
        $token = (string) ($input['invitationToken'] ?? '');
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invitation invalide.');
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('Le mot de passe doit contenir au moins 12 caractères.');
        }
        if (!in_array($input['terms'] ?? null, [true, 1, '1', 'on', 'true'], true)) {
            throw new RuntimeException('Vous devez accepter les CGU et la politique de confidentialité.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, organization_id, email, first_name, last_name
                 FROM organization_invitations
                 WHERE token_hash = :token_hash AND accepted_at IS NULL AND revoked_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $invitation = $statement->fetch();
            if (!is_array($invitation) || strtolower((string) $invitation['email']) !== $email) {
                throw new RuntimeException('Cette invitation est invalide, expirée ou ne correspond pas à cette adresse.');
            }

            $user = $this->pdo->prepare(
                'INSERT INTO users
                 (organization_id, email, password_hash, first_name, last_name, role, status, email_verified_at)
                 VALUES (:organization_id, :email, :password_hash, :first_name, :last_name, :role, :status, UTC_TIMESTAMP())'
            );
            $user->execute([
                'organization_id' => $invitation['organization_id'],
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $invitation['first_name'] ?: 'Utilisateur',
                'last_name' => $invitation['last_name'] ?: 'Invité',
                'role' => 'user',
                'status' => 'active',
            ]);
            $accepted = $this->pdo->prepare('UPDATE organization_invitations SET accepted_at = UTC_TIMESTAMP() WHERE id = :id');
            $accepted->execute(['id' => $invitation['id']]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw $exception;
        }
    }
}
