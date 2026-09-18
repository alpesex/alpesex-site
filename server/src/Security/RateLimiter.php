<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Security;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertAllowed(
        string $action,
        string $clientIdentifier,
        int $maximumAttempts,
        int $windowSeconds
    ): void {
        $clientHash = hash('sha256', $clientIdentifier);
        // MariaDB stores window_started_at with UTC_TIMESTAMP(). Always parse and
        // compare it in UTC, independently from the Europe/Paris application timezone.
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        $windowStart = $now->modify("-{$windowSeconds} seconds");

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT attempts, window_started_at
                 FROM request_rate_limits
                 WHERE action_key = :action_key AND client_hash = :client_hash
                 FOR UPDATE'
            );
            $select->execute(['action_key' => $action, 'client_hash' => $clientHash]);
            $record = $select->fetch();

            $recordStartedAt = $record
                ? new DateTimeImmutable((string) $record['window_started_at'], $utc)
                : null;
            if (!$record || $recordStartedAt < $windowStart) {
                $replace = $this->pdo->prepare(
                    'INSERT INTO request_rate_limits
                        (action_key, client_hash, attempts, window_started_at)
                     VALUES (:action_key, :client_hash, 1, UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE attempts = 1, window_started_at = UTC_TIMESTAMP()'
                );
                $replace->execute(['action_key' => $action, 'client_hash' => $clientHash]);
                $this->pdo->commit();
                return;
            }

            if ((int) $record['attempts'] >= $maximumAttempts) {
                $this->pdo->rollBack();
                throw new RuntimeException('Trop de tentatives. Veuillez réessayer plus tard.');
            }

            $increment = $this->pdo->prepare(
                'UPDATE request_rate_limits
                 SET attempts = attempts + 1
                 WHERE action_key = :action_key AND client_hash = :client_hash'
            );
            $increment->execute(['action_key' => $action, 'client_hash' => $clientHash]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
