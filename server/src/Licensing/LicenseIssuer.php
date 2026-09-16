<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Licensing;

use AlpesEx\Portal\Config;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class LicenseIssuer
{
    public const AUTHORITY_ID = 'alpesex-cpmp-production-2026';
    private const SIGNING_PREFIX = 'ASM-LICENSE-V1.';
    private string $secretKey;

    public function __construct(Config $config)
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new RuntimeException('Extension Sodium indisponible pour la signature des licences.');
        }

        $path = $config->string('ALPESEX_LICENSE_PRIVATE_KEY_PATH');
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Clé privée de licences inaccessible.');
        }

        $seed = $this->ed25519SeedFromPkcs8((string) file_get_contents($path));
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
    }

    /** @return array{token:string,claims:array<string,mixed>} */
    public function issueMaster(
        string $licenseId,
        string $organizationId,
        string $company,
        string $plan,
        DateTimeImmutable $expiresAt
    ): array {
        return $this->issue([
            'schema' => 2,
            'type' => 'master',
            'id' => $this->identifier($licenseId),
            'organizationId' => $this->identifier($organizationId),
            'company' => $this->requiredText($company, 150),
            'plan' => $this->identifier($plan),
            'authorityId' => self::AUTHORITY_ID,
            'issuedAt' => $this->milliseconds(new DateTimeImmutable('now')),
            'expiresAt' => $this->milliseconds($expiresAt),
        ]);
    }

    /** @return array{token:string,claims:array<string,mixed>} */
    public function issueUser(
        string $licenseId,
        string $organizationId,
        string $masterId,
        string $company,
        string $email,
        string $role,
        DateTimeImmutable $expiresAt
    ): array {
        $normalizedEmail = strtolower(trim($email));
        if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Adresse e-mail de licence invalide.');
        }
        if (!in_array($role, ['user', 'manager', 'direction'], true)) {
            throw new RuntimeException('Type de licence invalide.');
        }

        return $this->issue([
            'schema' => 2,
            'type' => 'user',
            'id' => $this->identifier($licenseId),
            'organizationId' => $this->identifier($organizationId),
            'masterId' => $this->identifier($masterId),
            'company' => $this->requiredText($company, 150),
            'email' => $normalizedEmail,
            'role' => $role,
            'maxDevices' => 3,
            'authorityId' => self::AUTHORITY_ID,
            'issuedAt' => $this->milliseconds(new DateTimeImmutable('now')),
            'expiresAt' => $this->milliseconds($expiresAt),
        ]);
    }

    /** @param array<string,mixed> $claims @return array{token:string,claims:array<string,mixed>} */
    private function issue(array $claims): array
    {
        try {
            $json = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Impossible de sérialiser la licence.', 0, $exception);
        }
        $body = $this->base64Url($json);
        $signature = sodium_crypto_sign_detached(self::SIGNING_PREFIX . $body, $this->secretKey);
        return ['token' => $body . '.' . $this->base64Url($signature), 'claims' => $claims];
    }

    private function ed25519SeedFromPkcs8(string $pem): string
    {
        if (!preg_match('/-----BEGIN PRIVATE KEY-----\s*(.*?)\s*-----END PRIVATE KEY-----/s', $pem, $match)) {
            throw new RuntimeException('Format de clé privée invalide.');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $match[1]) ?? '', true);
        $prefix = hex2bin('302e020100300506032b657004220420');
        if ($der === false || $prefix === false || strlen($der) !== 48 || !str_starts_with($der, $prefix)) {
            throw new RuntimeException('La clé privée doit être une clé Ed25519 PKCS#8.');
        }
        return substr($der, 16, 32);
    }

    private function identifier(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value)) {
            throw new RuntimeException('Identifiant de licence invalide.');
        }
        return $value;
    }

    private function requiredText(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new RuntimeException('Information de licence invalide.');
        }
        return $value;
    }

    private function milliseconds(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U')) * 1000 + intdiv((int) $date->format('u'), 1000);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
