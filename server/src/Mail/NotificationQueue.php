<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Mail;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class NotificationQueue
{
    private const TYPES = [
        'order_confirmation',
        'invoice_available',
        'subscription_confirmation',
        'payment_failure',
        'trial_expiring',
        'license_limit',
        'promotional_communication',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionalMailer $mailer
    ) {
    }

    /** @param array<string, scalar> $payload */
    public function enqueue(
        string $eventKey,
        string $type,
        string $recipient,
        array $payload
    ): bool {
        if ($eventKey === '' || strlen($eventKey) > 190) {
            throw new RuntimeException('Clé d’événement de notification invalide.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Type de notification inconnu.');
        }
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Destinataire de notification invalide.');
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Contenu de notification invalide.', 0, $exception);
        }

        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO email_notifications
             (event_key, notification_type, recipient, payload_json)
             VALUES (:event_key, :notification_type, :recipient, :payload_json)'
        );
        $statement->execute([
            'event_key' => $eventKey,
            'notification_type' => $type,
            'recipient' => strtolower($recipient),
            'payload_json' => $json,
        ]);

        return $statement->rowCount() === 1;
    }

    public function processNext(): bool
    {
        $this->pdo->beginTransaction();
        try {
            $recover = $this->pdo->prepare(
                "UPDATE email_notifications
                 SET status = 'pending', processing_started_at = NULL
                 WHERE status = 'processing'
                   AND processing_started_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE
                   AND attempts < 5"
            );
            $recover->execute();

            $select = $this->pdo->query(
                "SELECT id, notification_type, recipient, payload_json
                 FROM email_notifications
                 WHERE status IN ('pending', 'failed')
                   AND attempts < 5
                   AND available_at <= UTC_TIMESTAMP()
                 ORDER BY id ASC
                 LIMIT 1 FOR UPDATE"
            );
            $notification = $select->fetch();
            if (!is_array($notification)) {
                $this->pdo->commit();
                return false;
            }

            $claim = $this->pdo->prepare(
                "UPDATE email_notifications
                 SET status = 'processing', processing_started_at = UTC_TIMESTAMP(),
                     attempts = attempts + 1, last_error = NULL
                 WHERE id = :id"
            );
            $claim->execute(['id' => $notification['id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        try {
            $payload = json_decode(
                (string) $notification['payload_json'],
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($payload)) {
                throw new RuntimeException('Contenu de notification illisible.');
            }
            $this->dispatch(
                (string) $notification['notification_type'],
                (string) $notification['recipient'],
                $payload
            );
            $sent = $this->pdo->prepare(
                "UPDATE email_notifications
                 SET status = 'sent', sent_at = UTC_TIMESTAMP(),
                     processing_started_at = NULL, last_error = NULL
                 WHERE id = :id"
            );
            $sent->execute(['id' => $notification['id']]);
        } catch (Throwable $exception) {
            $delay = min(3600, 60 * (2 ** min(5, (int) ($notification['attempts'] ?? 0))));
            $availableAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . $delay . ' seconds')
                ->format('Y-m-d H:i:s');
            $failed = $this->pdo->prepare(
                "UPDATE email_notifications
                 SET status = 'failed', processing_started_at = NULL,
                     available_at = :available_at, last_error = :last_error
                 WHERE id = :id"
            );
            $failed->execute([
                'available_at' => $availableAt,
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
                'id' => $notification['id'],
            ]);
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function dispatch(string $type, string $recipient, array $payload): void
    {
        match ($type) {
            'order_confirmation' => $this->mailer->orderConfirmation(
                $recipient,
                $this->requiredString($payload, 'orderNumber'),
                $this->requiredString($payload, 'total')
            ),
            'invoice_available' => $this->mailer->invoiceAvailable(
                $recipient,
                $this->requiredString($payload, 'invoiceNumber'),
                $this->requiredString($payload, 'invoiceUrl')
            ),
            'subscription_confirmation' => $this->mailer->subscriptionConfirmation(
                $recipient,
                $this->requiredString($payload, 'offerName')
            ),
            'payment_failure' => $this->mailer->paymentFailure(
                $recipient,
                $this->requiredString($payload, 'actionUrl')
            ),
            'trial_expiring' => $this->mailer->trialExpiring(
                $recipient,
                $this->requiredString($payload, 'expirationDate'),
                $this->requiredString($payload, 'offerUrl')
            ),
            'license_limit' => $this->mailer->licenseLimit(
                $recipient,
                $this->requiredInteger($payload, 'used'),
                $this->requiredInteger($payload, 'available')
            ),
            'promotional_communication' => $this->mailer->promotionalCommunication(
                $recipient,
                $this->requiredString($payload, 'title'),
                $this->requiredString($payload, 'content'),
                $this->requiredString($payload, 'actionLabel'),
                $this->requiredString($payload, 'actionUrl'),
                $this->requiredString($payload, 'unsubscribeUrl')
            ),
            default => throw new RuntimeException('Type de notification inconnu.'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException('Champ de notification manquant : ' . $key);
        }
        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredInteger(array $payload, string $key): int
    {
        $value = filter_var($payload[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0) {
            throw new RuntimeException('Champ de notification invalide : ' . $key);
        }
        return $value;
    }
}
