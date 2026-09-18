<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Mail;

use PDO;
use RuntimeException;
use Throwable;

final class MarketingUnsubscribe
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException('Lien de désinscription invalide ou expiré.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT id, user_id
                 FROM auth_tokens
                 WHERE token_hash = :token_hash
                   AND purpose = 'marketing_unsubscribe'
                   AND used_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $unsubscribe = $statement->fetch();
            if (!is_array($unsubscribe)) {
                throw new RuntimeException('Lien de désinscription invalide ou expiré.');
            }

            $user = $this->pdo->prepare(
                'UPDATE users
                 SET marketing_opt_in_at = NULL, marketing_opt_out_at = UTC_TIMESTAMP()
                 WHERE id = :user_id'
            );
            $user->execute(['user_id' => $unsubscribe['user_id']]);

            $consume = $this->pdo->prepare(
                "UPDATE auth_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id AND purpose = 'marketing_unsubscribe' AND used_at IS NULL"
            );
            $consume->execute(['user_id' => $unsubscribe['user_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
