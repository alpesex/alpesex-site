<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\Licensing\LicenseTokenVerifier;
use AlpesEx\Portal\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
    $token = is_array($input) ? trim((string) ($input['licenseToken'] ?? '')) : '';
    if ($token === '' || strlen($token) > 20000) {
        throw new RuntimeException('Licence absente.');
    }

    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    (new RateLimiter($pdo))->assertAllowed(
        'license_online_status',
        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . substr(hash('sha256', $token), 0, 16),
        120,
        3600
    );

    $claims = (new LicenseTokenVerifier())->verify($token);
    $statement = $pdo->prepare(
        'SELECT r.id,r.license_type,r.status,r.expires_at,o.status AS organization_status,
                parent.status AS parent_master_status,
                parent.expires_at AS parent_master_expires_at
         FROM organization_license_registry r
         INNER JOIN organizations o ON o.id=r.organization_id
         LEFT JOIN organization_license_registry parent
           ON parent.organization_id=r.organization_id
          AND parent.issuer_license_id=r.parent_master_license_id
          AND parent.license_type=\'master\'
         WHERE r.issuer_license_id=:license_id AND r.token_hash=:token_hash
           AND r.license_type=:license_type LIMIT 1'
    );
    $statement->execute([
        'license_id' => $claims['id'],
        'token_hash' => hash('sha256', $token),
        'license_type' => $claims['type'],
    ]);
    $license = $statement->fetch();
    if (!is_array($license)) {
        echo json_encode([
            'registered' => false,
            'active' => false,
            'status' => 'unregistered',
            'checkedAt' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $expired = $license['expires_at'] !== null && strtotime((string) $license['expires_at'] . ' UTC') <= time();
    $parentExpired = $license['parent_master_expires_at'] !== null
        && strtotime((string) $license['parent_master_expires_at'] . ' UTC') <= time();
    $masterActive = $license['license_type'] === 'master'
        || ($license['parent_master_status'] === 'active' && !$parentExpired);
    $active = $license['status'] === 'active'
        && $license['organization_status'] === 'active'
        && !$expired
        && $masterActive;
    $status = $expired ? 'expired'
        : ($license['organization_status'] !== 'active' ? 'organization_suspended'
            : (!$masterActive ? 'master_suspended' : (string) $license['status']));
    $update = $pdo->prepare('UPDATE organization_license_registry SET last_online_check_at=UTC_TIMESTAMP() WHERE id=:id');
    $update->execute(['id' => $license['id']]);

    echo json_encode([
        'registered' => true,
        'active' => $active,
        'status' => $status,
        'licenseId' => $claims['id'],
        'checkedAt' => gmdate(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['message' => 'Données JSON invalides.'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(str_starts_with($exception->getMessage(), 'Trop de tentatives') ? 429 : 422);
    echo json_encode(['message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['message' => 'Le contrôle central est momentanément indisponible.'], JSON_UNESCAPED_UNICODE);
}
