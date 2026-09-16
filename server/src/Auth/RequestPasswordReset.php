<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Auth;

use AlpesEx\Portal\Config;
use AlpesEx\Portal\Mail\Mailer;
use AlpesEx\Portal\Mail\TransactionalMailer;
use DateTimeImmutable;
use PDO;

final class RequestPasswordReset
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Mailer $mailer
    ) {
    }

    public function execute(string $rawEmail): void
    {
        $email = strtolower(trim($rawEmail));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $statement = $this->pdo->prepare(
            "SELECT id, email, first_name
             FROM users
             WHERE email = :email AND status = 'active' AND email_verified_at IS NOT NULL
             LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->pdo->beginTransaction();
        try {
            $revoke = $this->pdo->prepare(
                "UPDATE auth_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id AND purpose = 'password_reset' AND used_at IS NULL"
            );
            $revoke->execute(['user_id' => $user['id']]);

            $insert = $this->pdo->prepare(
                'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at)
                 VALUES (:user_id, :purpose, :token_hash, :expires_at)'
            );
            $insert->execute([
                'user_id' => $user['id'],
                'purpose' => 'password_reset',
                'token_hash' => hash('sha256', $token),
                'expires_at' => (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s'),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $url = rtrim($this->config->string('APP_URL'), '/')
            . '/compte/?reset=' . rawurlencode($token);
        (new TransactionalMailer($this->mailer))->passwordReset(
            (string) $user['email'],
            (string) $user['first_name'],
            $url
        );
    }
}
