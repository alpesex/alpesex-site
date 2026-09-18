<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Licensing;

use JsonException;
use RuntimeException;

final class LicenseTokenVerifier
{
    public function __construct(
        private readonly string $privateKeyPath = '/home/www/private/licenses/issuer-private.pem'
    ) {
    }

    /** @return array<string,mixed> */
    public function verify(string $token): array
    {
        $token = trim($token);
        if (strlen($token) > 20000 || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw new RuntimeException('Format de licence invalide.');
        }
        [$body, $signature] = explode('.', $token, 2);
        $signatureBytes = $this->base64UrlDecode($signature);
        if (strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $signatureBytes,
                'ASM-LICENSE-V1.' . $body,
                $this->publicKey()
            )) {
            throw new RuntimeException('Signature de licence invalide.');
        }
        try {
            $claims = json_decode($this->base64UrlDecode($body), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Contenu de licence invalide.', 0, $exception);
        }
        if (!is_array($claims) || !in_array($claims['schema'] ?? null, [1, 2], true)) {
            throw new RuntimeException('Version de licence incompatible.');
        }
        foreach (['id', 'organizationId', 'type'] as $field) {
            if (!is_string($claims[$field] ?? null) || ($claims[$field] ?? '') === '') {
                throw new RuntimeException('Contenu de licence incomplet.');
            }
        }
        if (!in_array($claims['type'], ['master', 'user'], true)) {
            throw new RuntimeException('Type de licence incompatible.');
        }
        if ($claims['type'] === 'user') {
            if (!is_string($claims['masterId'] ?? null)
                || filter_var($claims['email'] ?? null, FILTER_VALIDATE_EMAIL) === false
                || (int) ($claims['maxDevices'] ?? 0) !== 3) {
                throw new RuntimeException('Licence utilisateur incomplète.');
            }
        }
        return $claims;
    }

    private function publicKey(): string
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('L’extension PHP Sodium est requise.');
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
        $marker = "\x04\x20";
        $position = is_string($der) ? strrpos($der, $marker) : false;
        $seed = $position === false ? false : substr($der, $position + 2, 32);
        if (!is_string($seed) || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new RuntimeException('Impossible de lire la clé privée Ed25519.');
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);
        return sodium_crypto_sign_publickey($pair);
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new RuntimeException('Encodage de licence invalide.');
        }
        return $decoded;
    }
}
