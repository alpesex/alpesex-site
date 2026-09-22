<?php

declare(strict_types=1);

namespace AlpesEx\Portal\AgentCockpit;

use PDO;
use RuntimeException;

final class OAuth
{
    public const ISSUER = 'https://alpes-ex.fr';
    public const RESOURCE = 'https://alpes-ex.fr/api/admin/agents/mcp/';
    public const CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
    public const SCOPE = 'agent_cockpit:coordinate';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function enabled(): bool
    {
        return ($_ENV['ALPESEX_MCP_ENABLED'] ?? getenv('ALPESEX_MCP_ENABLED') ?: '') === '1';
    }

    public static function allowedRedirect(string $uri): bool
    {
        $configured = (string) ($_ENV['ALPESEX_MCP_REDIRECT_URIS'] ?? getenv('ALPESEX_MCP_REDIRECT_URIS') ?: '');
        if ($uri === '' || !str_starts_with($uri, 'https://')) {
            return false;
        }
        return in_array($uri, array_map('trim', explode(',', $configured)), true);
    }

    public function activeAdmin(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT email, status FROM users WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!is_array($user) || $user['status'] !== 'active') {
            return false;
        }
        $configured = (string) ($_ENV['ALPESEX_ADMIN_EMAILS'] ?? getenv('ALPESEX_ADMIN_EMAILS') ?: '');
        $allowed = array_map(static fn (string $value): string => mb_strtolower(trim($value)), explode(',', $configured));
        return in_array(mb_strtolower((string) $user['email']), $allowed, true);
    }

    public static function validateAuthorization(array $params): void
    {
        if (($params['client_id'] ?? null) !== self::CLIENT_ID
            || !self::allowedRedirect((string) ($params['redirect_uri'] ?? ''))
            || ($params['response_type'] ?? null) !== 'code'
            || ($params['resource'] ?? null) !== self::RESOURCE
            || ($params['code_challenge_method'] ?? null) !== 'S256'
            || !is_string($params['code_challenge'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $params['code_challenge']) !== 1
            || ($params['scope'] ?? null) !== self::SCOPE
            || !is_string($params['state'] ?? null)
            || strlen($params['state']) < 8 || strlen($params['state']) > 200) {
            throw new RuntimeException('Demande OAuth invalide.', 400);
        }
    }

    public function issueCode(int $userId, array $params): string
    {
        self::validateAuthorization($params);
        if (!$this->activeAdmin($userId)) {
            throw new RuntimeException('Accès administrateur requis.', 403);
        }
        $this->pdo->exec('DELETE FROM agent_oauth_codes WHERE expires_at < UTC_TIMESTAMP()');
        $code = self::randomToken();
        $stmt = $this->pdo->prepare(
            'INSERT INTO agent_oauth_codes
             (code_hash, user_id, client_id, redirect_uri, code_challenge, resource, scope, expires_at)
             VALUES (:hash, :user, :client, :redirect, :challenge, :resource, :scope, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))'
        );
        $stmt->execute([
            'hash' => hash('sha256', $code), 'user' => $userId,
            'client' => self::CLIENT_ID, 'redirect' => $params['redirect_uri'],
            'challenge' => $params['code_challenge'], 'resource' => self::RESOURCE,
            'scope' => self::SCOPE,
        ]);
        return $code;
    }

    public function exchange(array $params): array
    {
        $code = $params['code'] ?? null;
        $verifier = $params['code_verifier'] ?? null;
        if (($params['grant_type'] ?? null) !== 'authorization_code'
            || ($params['client_id'] ?? null) !== self::CLIENT_ID
            || ($params['resource'] ?? null) !== self::RESOURCE
            || !self::allowedRedirect((string) ($params['redirect_uri'] ?? ''))
            || !is_string($code) || strlen($code) < 40 || strlen($code) > 128
            || !is_string($verifier) || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
            throw new RuntimeException('Échange OAuth invalide.', 400);
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM agent_oauth_codes WHERE code_hash=:hash AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $hash = hash('sha256', $code);
            $query->execute(['hash' => $hash]);
            $record = $query->fetch();
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!is_array($record) || !hash_equals((string) $record['code_challenge'], $challenge)
                || $record['client_id'] !== self::CLIENT_ID || $record['redirect_uri'] !== $params['redirect_uri']
                || $record['resource'] !== self::RESOURCE || $record['scope'] !== self::SCOPE
                || !$this->activeAdmin((int) $record['user_id'])) {
                throw new RuntimeException('Code OAuth invalide ou expiré.', 400);
            }
            $delete = $this->pdo->prepare('DELETE FROM agent_oauth_codes WHERE code_hash=:hash');
            $delete->execute(['hash' => $hash]);
            $token = self::randomToken();
            $insert = $this->pdo->prepare(
                'INSERT INTO agent_oauth_tokens (token_hash, user_id, client_id, resource, scope, expires_at)
                 VALUES (:hash, :user, :client, :resource, :scope, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))'
            );
            $insert->execute([
                'hash' => hash('sha256', $token), 'user' => $record['user_id'],
                'client' => self::CLIENT_ID, 'resource' => self::RESOURCE, 'scope' => self::SCOPE,
            ]);
            $this->pdo->commit();
            return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => self::SCOPE];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function authorizeBearer(?string $header): bool
    {
        if (!self::enabled() || $header === null || preg_match('/^Bearer ([A-Za-z0-9_-]{40,128})$/D', $header, $match) !== 1) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT user_id, client_id, resource, scope FROM agent_oauth_tokens
             WHERE token_hash=:hash AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $match[1])]);
        $record = $stmt->fetch();
        return is_array($record) && $record['client_id'] === self::CLIENT_ID
            && $record['resource'] === self::RESOURCE && $record['scope'] === self::SCOPE
            && $this->activeAdmin((int) $record['user_id']);
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }
}
