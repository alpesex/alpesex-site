<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Mail;

use AlpesEx\Portal\Config;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class MarketingCampaign
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly NotificationQueue $queue
    ) {
    }

    public function prepare(
        string $campaignKey,
        string $title,
        string $content,
        string $actionLabel,
        string $actionUrl
    ): int {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $campaignKey)) {
            throw new RuntimeException('Identifiant de campagne invalide.');
        }
        if ($title === '' || mb_strlen($title) > 150) {
            throw new RuntimeException('Titre de campagne invalide.');
        }
        if ($content === '' || mb_strlen($content) > 5000) {
            throw new RuntimeException('Contenu de campagne invalide.');
        }
        if ($actionLabel === '' || mb_strlen($actionLabel) > 80) {
            throw new RuntimeException('Libellé d’action invalide.');
        }
        if (filter_var($actionUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Lien d’action invalide.');
        }

        $recipients = $this->pdo->query(
            "SELECT id, email
             FROM users
             WHERE marketing_opt_in_at IS NOT NULL
               AND marketing_opt_out_at IS NULL
               AND status = 'active'
               AND email_verified_at IS NOT NULL
             ORDER BY id ASC"
        );
        $alreadyQueued = $this->pdo->prepare(
            'SELECT id FROM email_notifications WHERE event_key = :event_key LIMIT 1'
        );
        $insertToken = $this->pdo->prepare(
            "INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at)
             VALUES (:user_id, 'marketing_unsubscribe', :token_hash, :expires_at)"
        );

        $count = 0;
        foreach ($recipients->fetchAll() as $recipient) {
            $eventKey = 'marketing:' . $campaignKey . ':user:' . $recipient['id'];
            $alreadyQueued->execute(['event_key' => $eventKey]);
            if ($alreadyQueued->fetch()) {
                continue;
            }

            $token = bin2hex(random_bytes(32));
            $insertToken->execute([
                'user_id' => $recipient['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->modify('+1 year')
                    ->format('Y-m-d H:i:s'),
            ]);
            $unsubscribeUrl = rtrim($this->config->string('APP_URL'), '/')
                . '/api/marketing/unsubscribe/?token=' . rawurlencode($token);

            if ($this->queue->enqueue(
                $eventKey,
                'promotional_communication',
                (string) $recipient['email'],
                [
                    'title' => $title,
                    'content' => $content,
                    'actionLabel' => $actionLabel,
                    'actionUrl' => $actionUrl,
                    'unsubscribeUrl' => $unsubscribeUrl,
                ]
            )) {
                $count++;
            }
        }

        return $count;
    }
}
