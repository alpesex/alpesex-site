<?php

declare(strict_types=1);

namespace AlpesEx\Portal;

use RuntimeException;

final class Config
{
    /** @param array<string, mixed> $environment */
    public function __construct(private readonly array $environment)
    {
    }

    public function string(string $key): string
    {
        $value = trim((string) ($this->environment[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException("Configuration manquante : {$key}");
        }

        return $value;
    }

    public function integer(string $key): int
    {
        $value = filter_var($this->string($key), FILTER_VALIDATE_INT);

        if ($value === false || $value < 1 || $value > 65535) {
            throw new RuntimeException("Configuration invalide : {$key}");
        }

        return $value;
    }

    public function email(string $key): string
    {
        $value = $this->string($key);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException("Adresse e-mail invalide : {$key}");
        }

        return $value;
    }
}
