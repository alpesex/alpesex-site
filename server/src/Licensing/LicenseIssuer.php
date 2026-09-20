<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Licensing;

use RuntimeException;

final class LicenseIssuer
{
    public function __construct(
        private readonly string $privateKeyPath = '/home/www/private/licenses/issuer-private.pem'
    ) {
    }

    /** @param array<string,mixed> $claims */
    public function issueMaster(array $claims): string
    {
        return $this->issue('master', $claims);
    }

    /** @param array<string,mixed> $claims */
    public function issueUser(array $claims): string
    {
        $claims['maxDevices'] = 3;
        return $this->issue('user', $claims);
    }

    /** @param array<string,mixed> $claims */
    private function issue(string $type, array $claims): string
    {
        foreach (['id', 'organizationId', 'deploymentId'] as $field) {
            $this->assertIdentifier($claims[$field] ?? null, $field);
        }
        if ($type === 'master') {
            $this->assertIdentifier($claims['installationPublicKey'] ?? null, 'installationPublicKey', false);
        } elseif ($type === 'user') {
            $this->assertIdentifier($claims['masterId'] ?? null, 'masterId');
            $email = $claims['email'] ?? null;
            if (!is_string($email) || $email !== strtolower(trim($email))
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                throw new RuntimeException('Adresse e-mail de licence invalide.');
            }
        } else {
            throw new RuntimeException('Type de licence inconnu.');
        }

        $payload = [
            ...$claims,
            'schema' => 1,
            'type' => $type,
            'issuedAt' => (int) floor(microtime(true) * 1000),
            'expiresAt' => $claims['expiresAt'] ?? null,
        ];
        if ($type === 'user') {
            $payload['maxDevices'] = 3;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $body = $this->base64UrlEncode($json);
        $signature = sodium_crypto_sign_detached(
            'ASM-LICENSE-V1.' . $body,
            $this->secretKey()
        );

        return $body . '.' . $this->base64UrlEncode($signature);
    }

    /** Reissue an existing, verified seat without changing its role, Master or expiry. */
    public function reassignUser(array $claims, string $email): string
    {
        if (($claims['type'] ?? '') !== 'user' || !in_array($claims['schema'] ?? null, [1, 2], true)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $email !== strtolower(trim($email))) {
            throw new RuntimeException('Réattribution de licence invalide.');
        }
        $claims['email'] = $email;
        $claims['issuedAt'] = max((int) ($claims['issuedAt'] ?? 0) + 1, (int) floor(microtime(true) * 1000));
        $claims['maxDevices'] = 3;
        $body = $this->base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $body . '.' . $this->base64UrlEncode(sodium_crypto_sign_detached('ASM-LICENSE-V1.' . $body, $this->secretKey()));
    }

    private function secretKey(): string
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('L’extension PHP Sodium est requise pour signer les licences.');
        }
        if (!is_file($this->privateKeyPath) || !is_readable($this->privateKeyPath)) {
            throw new RuntimeException('La clé privée de licences est absente ou illisible.');
        }

        $pem = file_get_contents($this->privateKeyPath);
        if (!is_string($pem)
            || !preg_match('/-----BEGIN PRIVATE KEY-----\s*(.*?)\s*-----END PRIVATE KEY-----/s', $pem, $match)) {
            throw new RuntimeException('Format de clé privée invalide.');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $match[1]) ?: '', true);
        if (!is_string($der) || !str_contains($der, "\x06\x03\x2B\x65\x70")) {
            throw new RuntimeException('La clé privée doit être une clé Ed25519 PKCS#8.');
        }

        $marker = "\x04\x20";
        $position = strrpos($der, $marker);
        $seed = $position === false ? false : substr($der, $position + strlen($marker), 32);
        if (!is_string($seed) || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new RuntimeException('Impossible de lire la clé privée Ed25519.');
        }

        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
    }

    private function assertIdentifier(mixed $value, string $field, bool $restricted = true): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > ($restricted ? 100 : 10000)) {
            throw new RuntimeException("Champ de licence invalide : {$field}");
        }
        if ($restricted && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value) !== 1) {
            throw new RuntimeException("Identifiant de licence invalide : {$field}");
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
