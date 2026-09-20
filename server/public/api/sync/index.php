<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\Licensing\LicenseTokenVerifier;
use AlpesEx\Portal\Security\RateLimiter;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function failSync(int $status, string $error): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return array{organizationId:string,licenseId:string,token:string} */
function syncLicense(string $token): array
{
    if ($token === '' || strlen($token) > 20000) {
        failSync(401, 'LICENSE_REQUIRED');
    }
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 3) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    (new RateLimiter($pdo))->assertAllowed(
        'encrypted_sync',
        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . substr(hash('sha256', $token), 0, 16),
        1200,
        3600
    );
    $claims = (new LicenseTokenVerifier())->verify($token);
    if (($claims['type'] ?? '') !== 'user') {
        failSync(403, 'USER_LICENSE_REQUIRED');
    }
    $statement = $pdo->prepare(
        "SELECT r.organization_id,r.status,r.expires_at,o.status AS organization_status,
                parent.status AS parent_status,parent.expires_at AS parent_expires_at
         FROM organization_license_registry r
         INNER JOIN organizations o ON o.id=r.organization_id
         LEFT JOIN organization_license_registry parent
           ON parent.organization_id=r.organization_id
          AND parent.issuer_license_id=r.parent_master_license_id
          AND parent.license_type='master'
         WHERE r.issuer_license_id=:license_id AND r.token_hash=:token_hash
           AND r.license_type='user' LIMIT 1"
    );
    $statement->execute(['license_id' => $claims['id'], 'token_hash' => hash('sha256', $token)]);
    $row = $statement->fetch();
    $expired = is_array($row) && $row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') <= time();
    $parentExpired = is_array($row) && $row['parent_expires_at'] !== null && strtotime((string) $row['parent_expires_at'] . ' UTC') <= time();
    if (!is_array($row) || $row['status'] !== 'active' || $row['organization_status'] !== 'active'
        || $row['parent_status'] !== 'active' || $expired || $parentExpired) {
        failSync(403, is_array($row) ? 'LICENSE_SUSPENDED' : 'LICENSE_NOT_REGISTERED');
    }
    return ['organizationId' => (string) $row['organization_id'], 'licenseId' => (string) $claims['id'], 'token' => $token];
}

function objectKey(string $value): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 240 || str_contains($value, '..')
        || preg_match('#^(backups/[\p{L}\p{N}._-]+\.json|documents/[a-f0-9]{64})$#u', $value) !== 1) {
        failSync(422, 'INVALID_OBJECT_KEY');
    }
    return $value;
}

/** @param array{organizationId:string,licenseId:string,token:string} $license */
function storage(array $license, string $key): array
{
    $root = getenv('ALPESEX_SYNC_DIR') ?: '/home/www/private/sync';
    $licensePart = preg_replace('/[^A-Za-z0-9._-]/', '_', $license['licenseId']) ?: 'invalid';
    $directory = $root . '/' . $license['organizationId'] . '/' . $licensePart;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        failSync(503, 'SYNC_STORAGE_UNAVAILABLE');
    }
    $name = hash('sha256', $key);
    return [$directory . '/' . $name . '.blob', $directory . '/' . $name . '.json'];
}

try {
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || ($input['action'] ?? '') !== 'list') {
            failSync(422, 'INVALID_REQUEST');
        }
        $license = syncLicense(trim((string) ($input['licenseToken'] ?? '')));
        $prefix = (string) ($input['prefix'] ?? '');
        if (!in_array($prefix, ['backups/', 'documents/'], true)
            && preg_match('#^documents/[a-f0-9]{64}$#', $prefix) !== 1) {
            failSync(422, 'INVALID_PREFIX');
        }
        [, $sampleMeta] = storage($license, $prefix . 'sample');
        $directory = dirname($sampleMeta);
        $objects = [];
        foreach (glob($directory . '/*.json') ?: [] as $metaFile) {
            $record = json_decode(file_get_contents($metaFile) ?: '', true);
            if (!is_array($record) || !is_string($record['key'] ?? null)
                || !str_starts_with($record['key'], $prefix)) {
                continue;
            }
            $objects[] = [
                'key' => $record['key'],
                'bytes' => (int) ($record['bytes'] ?? 0),
                'updatedAt' => (string) ($record['updatedAt'] ?? ''),
                'metadata' => is_array($record['metadata'] ?? null) ? $record['metadata'] : [],
            ];
        }
        usort($objects, static fn(array $a, array $b): int => strcmp($b['updatedAt'], $a['updatedAt']));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['objects' => $objects], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = trim((string) ($_SERVER['HTTP_X_ASM_LICENSE'] ?? ''));
    $license = syncLicense($token);
    $key = objectKey((string) ($_GET['object'] ?? ''));
    [$blobFile, $metaFile] = storage($license, $key);

    if ($method === 'PUT') {
        $encodedMetadata = (string) ($_SERVER['HTTP_X_ASM_METADATA'] ?? '');
        if (strlen($encodedMetadata) > 8000 || preg_match('/^[A-Za-z0-9_-]*$/', $encodedMetadata) !== 1) {
            failSync(422, 'INVALID_METADATA');
        }
        $padding = (4 - strlen($encodedMetadata) % 4) % 4;
        $decoded = base64_decode(strtr($encodedMetadata . str_repeat('=', $padding), '-_', '+/'), true);
        $metadata = json_decode(is_string($decoded) ? $decoded : '{}', true);
        if (!is_array($metadata)) {
            failSync(422, 'INVALID_METADATA');
        }
        $temporary = $blobFile . '.upload-' . bin2hex(random_bytes(8));
        $input = fopen('php://input', 'rb');
        $output = fopen($temporary, 'xb');
        if ($input === false || $output === false) {
            failSync(503, 'SYNC_STORAGE_UNAVAILABLE');
        }
        $bytes = 0;
        while (!feof($input)) {
            $chunk = fread($input, 1048576);
            if (!is_string($chunk)) {
                @unlink($temporary);
                failSync(400, 'UPLOAD_INTERRUPTED');
            }
            $bytes += strlen($chunk);
            if ($bytes > 56 * 1024 * 1024) {
                fclose($output);
                @unlink($temporary);
                failSync(413, 'REQUEST_TOO_LARGE');
            }
            fwrite($output, $chunk);
        }
        fclose($input);
        fclose($output);
        if ($bytes < 37) {
            @unlink($temporary);
            failSync(422, 'SYNC_DATA_INVALID');
        }
        rename($temporary, $blobFile);
        $record = ['schema' => 1, 'key' => $key, 'bytes' => $bytes, 'updatedAt' => gmdate(DATE_ATOM), 'metadata' => $metadata];
        file_put_contents($metaFile . '.tmp', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        rename($metaFile . '.tmp', $metaFile);
        chmod($blobFile, 0600);
        chmod($metaFile, 0600);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'key' => $key, 'bytes' => $bytes], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET') {
        if (!is_file($blobFile) || !is_file($metaFile)) {
            failSync(404, 'SYNC_OBJECT_NOT_FOUND');
        }
        $record = json_decode(file_get_contents($metaFile) ?: '', true);
        if (!is_array($record) || ($record['key'] ?? '') !== $key) {
            failSync(503, 'SYNC_DATA_INVALID');
        }
        $metadata = json_encode(is_array($record['metadata'] ?? null) ? $record['metadata'] : [], JSON_UNESCAPED_UNICODE);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($blobFile));
        header('X-ASM-Metadata: ' . rtrim(strtr(base64_encode($metadata ?: '{}'), '+/', '-_'), '='));
        readfile($blobFile);
        exit;
    }

    header('Allow: GET, PUT, POST');
    failSync(405, 'METHOD_NOT_ALLOWED');
} catch (JsonException) {
    failSync(400, 'INVALID_JSON');
} catch (RuntimeException $exception) {
    failSync(str_starts_with($exception->getMessage(), 'Trop de tentatives') ? 429 : 422, 'LICENSE_INVALID');
} catch (Throwable) {
    failSync(500, 'SYNC_UNAVAILABLE');
}
