<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

session_name('ALPESEXSESSID');
session_set_cookie_params(['path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

try {
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $organizationId = filter_var($_SESSION['organization_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || !$organizationId) {
        throw new RuntimeException('AUTHENTICATION_REQUIRED', 401);
    }

    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    $license = $pdo->prepare(
        "SELECT r.id
         FROM organization_license_registry r
         INNER JOIN organizations o ON o.id=r.organization_id
         LEFT JOIN organization_license_registry parent
           ON parent.organization_id=r.organization_id
          AND parent.issuer_license_id=r.parent_master_license_id
          AND parent.license_type='master'
         WHERE r.organization_id=:organization_id AND r.assigned_user_id=:user_id
           AND r.license_type='user' AND r.status='active' AND o.status='active'
           AND (r.expires_at IS NULL OR r.expires_at>UTC_TIMESTAMP())
           AND parent.status='active' AND (parent.expires_at IS NULL OR parent.expires_at>UTC_TIMESTAMP())
         LIMIT 1"
    );
    $license->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
    if ($license->fetchColumn() === false) {
        throw new RuntimeException('LICENSE_REQUIRED', 403);
    }

    $file = getenv('ALPESEX_WINDOWS_INSTALLER') ?: '/home/www/private/downloads/CPMP-ASM-Setup-V5.4.18.1-x64.exe';
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('INSTALLER_UNAVAILABLE', 404);
    }

    $size = filesize($file);
    if (!is_int($size) || $size <= 0) {
        throw new RuntimeException('INSTALLER_UNAVAILABLE', 404);
    }
    session_write_close();
    header('Content-Type: application/vnd.microsoft.portable-executable');
    header('Content-Disposition: attachment; filename="CPMP-ASM-Setup-V5.4.18.1-x64.exe"');
    header('Content-Length: ' . $size);
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
} catch (RuntimeException $exception) {
    http_response_code($exception->getCode() >= 400 ? $exception->getCode() : 400);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'DOWNLOAD_UNAVAILABLE'], JSON_UNESCAPED_UNICODE);
}
