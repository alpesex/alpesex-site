<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Mail;

use AlpesEx\Portal\Config;
use AlpesEx\Portal\Database;
use Throwable;

final class EmailDeliveryLogger
{
    public function __construct(private readonly Config $config)
    {
    }

    public function record(
        string $correlationId,
        string $messageType,
        string $recipient,
        string $status,
        ?string $smtpMessageId = null,
        ?string $errorCode = null
    ): void {
        try {
            $normalizedRecipient = strtolower(trim($recipient));
            $statement = Database::connect($this->config)->prepare(
                'INSERT INTO email_delivery_logs
                 (correlation_id, message_type, recipient, recipient_hash,
                  delivery_status, smtp_message_id, error_code)
                 VALUES
                 (:correlation_id, :message_type, :recipient, :recipient_hash,
                  :delivery_status, :smtp_message_id, :error_code)'
            );
            $statement->execute([
                'correlation_id' => $correlationId,
                'message_type' => mb_substr($messageType, 0, 64),
                'recipient' => $normalizedRecipient,
                'recipient_hash' => hash('sha256', $normalizedRecipient),
                'delivery_status' => $status,
                'smtp_message_id' => $smtpMessageId !== null
                    ? mb_substr($smtpMessageId, 0, 255)
                    : null,
                'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 64) : null,
            ]);
        } catch (Throwable) {
            // Une indisponibilité du journal ne doit jamais bloquer un e-mail métier.
        }
    }
}
