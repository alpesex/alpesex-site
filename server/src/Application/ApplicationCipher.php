<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Application;

use RuntimeException;

final class ApplicationCipher
{
    private const MAGIC = 'ASMAPP1';
    private readonly string $key;

    public function __construct(string $encodedKey)
    {
        $encodedKey = trim($encodedKey);
        $key = preg_match('/^[a-f0-9]{64}$/i', $encodedKey) === 1
            ? hex2bin($encodedKey)
            : base64_decode($encodedKey, true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('APPLICATION_ENCRYPTION_UNAVAILABLE', 503);
        }
        $this->key = $key;
    }

    public function encrypt(string $plain, string $context): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $context, 16);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            throw new RuntimeException('APPLICATION_ENCRYPTION_UNAVAILABLE', 503);
        }
        return base64_encode(self::MAGIC . $iv . $tag . $cipher);
    }

    public function decrypt(string $encoded, string $context): string
    {
        $blob = base64_decode($encoded, true);
        if (!is_string($blob) || strlen($blob) < 35 || substr($blob, 0, 7) !== self::MAGIC) {
            throw new RuntimeException('APPLICATION_DATA_INVALID', 503);
        }
        $plain = openssl_decrypt(
            substr($blob, 35), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
            substr($blob, 7, 12), substr($blob, 19, 16), $context
        );
        if (!is_string($plain)) {
            throw new RuntimeException('APPLICATION_DATA_INVALID', 503);
        }
        return $plain;
    }

    public function encryptBytes(string $plain, string $context): string
    {
        return base64_decode($this->encrypt($plain, $context), true) ?: '';
    }

    public function decryptBytes(string $blob, string $context): string
    {
        return $this->decrypt(base64_encode($blob), $context);
    }
}
