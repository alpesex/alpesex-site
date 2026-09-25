<?php

declare(strict_types=1);

namespace AlpesEx\Portal\AgentCockpit;

use AlpesEx\Portal\Mail\Mailer;
use AlpesEx\Portal\Mail\TransactionalMailer;
use Closure;

final class CoordinatorWake
{
    /** @var Closure(string): void */
    private readonly Closure $sender;
    private readonly ?string $recipient;

    /** @param array<string, mixed> $environment */
    public function __construct(array $environment, callable $sender)
    {
        $this->sender = Closure::fromCallable($sender);
        $this->recipient = $this->resolveRecipient($environment);
    }

    /** @param array<string, mixed> $environment */
    public static function usingMailer(array $environment, Mailer $mailer): self
    {
        return new self(
            $environment,
            static function (string $recipient) use ($mailer): void {
                (new TransactionalMailer($mailer))->coordinatorWake($recipient);
            }
        );
    }

    public function notify(): bool
    {
        if ($this->recipient === null) {
            return false;
        }
        ($this->sender)($this->recipient);
        return true;
    }

    /** @param array<string, mixed> $environment */
    private function resolveRecipient(array $environment): ?string
    {
        if (trim((string) ($environment['ALPESEX_COORDINATOR_WAKE_ENABLED'] ?? '')) !== '1') {
            return null;
        }

        $configured = trim((string) ($environment['ALPESEX_COORDINATOR_WAKE_EMAIL'] ?? ''));
        $candidates = $configured !== ''
            ? [$configured]
            : explode(',', (string) ($environment['ALPESEX_ADMIN_EMAILS'] ?? ''));

        foreach ($candidates as $candidate) {
            $email = mb_strtolower(trim((string) $candidate));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }
        return null;
    }
}
