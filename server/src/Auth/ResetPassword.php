<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Auth;

use PDO;
use RuntimeException;
use Throwable;

final class ResetPassword
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $token, string $password): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException('Ce lien de réinitialisation est invalide ou expiré.');
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('Le mot de passe doit contenir au moins 12 caractères.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT t.id, t.user_id
                 FROM auth_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = :token_hash
                   AND t.purpose = 'password_reset'
                   AND t.used_at IS NULL
                   AND t.expires_at > UTC_TIMESTAMP()
                   AND u.status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $reset = $statement->fetch();
            if (!is_array($reset)) {
                throw new RuntimeException('Ce lien de réinitialisation est invalide ou expiré.');
            }

            $update = $this->pdo->prepare(
                'UPDATE users SET password_hash = :password_hash WHERE id = :user_id'
            );
            $update->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'user_id' => $reset['user_id'],
            ]);

            $consume = $this->pdo->prepare(
                "UPDATE auth_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id AND purpose = 'password_reset' AND used_at IS NULL"
            );
            $consume->execute(['user_id' => $reset['user_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
