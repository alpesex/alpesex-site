<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Auth;

use PDO;
use RuntimeException;

final class ConfirmEmail
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException('Lien de confirmation invalide.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, user_id
                 FROM auth_tokens
                 WHERE token_hash = :token_hash AND purpose = :purpose
                   AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE'
            );
            $statement->execute([
                'token_hash' => hash('sha256', $token),
                'purpose' => 'email_verification',
            ]);
            $record = $statement->fetch();

            if (!is_array($record)) {
                throw new RuntimeException('Ce lien de confirmation est invalide ou expiré.');
            }

            $user = $this->pdo->prepare(
                "UPDATE users SET status = 'active', email_verified_at = UTC_TIMESTAMP() WHERE id = :id"
            );
            $user->execute(['id' => $record['user_id']]);

            $organization = $this->pdo->prepare(
                "UPDATE organizations o
                 INNER JOIN users u ON u.organization_id = o.id
                 SET o.status = 'active'
                 WHERE u.id = :user_id AND u.role = 'manager'"
            );
            $organization->execute(['user_id' => $record['user_id']]);

            $used = $this->pdo->prepare(
                'UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $used->execute(['id' => $record['id']]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
