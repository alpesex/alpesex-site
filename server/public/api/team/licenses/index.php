<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\Licensing\LicenseTokenVerifier;
use AlpesEx\Portal\Licensing\ManualLicenseRegistrar;
use AlpesEx\Portal\Mail\TransactionalMailer;
use AlpesEx\Portal\Security\RateLimiter;
use AlpesEx\Portal\Team\MemberManager;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $organizationId = filter_var($_SESSION['organization_id'] ?? null, FILTER_VALIDATE_INT);
    $isManager = ($_SESSION['role'] ?? '') === 'manager';
    if (!$userId || !$organizationId) {
        throw new RuntimeException('Authentification requise.', 403);
    }

    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $sql = 'SELECT r.issuer_license_id,r.license_type,r.license_role,r.assigned_email,
                       r.status,r.source,r.expires_at,r.last_online_check_at
                FROM organization_license_registry r
                WHERE r.organization_id=:organization_id';
        $parameters = ['organization_id' => $organizationId];
        if (!$isManager) {
            $sql .= ' AND r.assigned_user_id=:user_id';
            $parameters['user_id'] = $userId;
        }
        $statement = $pdo->prepare($sql . ' ORDER BY r.created_at DESC');
        $statement->execute($parameters);
        $licenses = $statement->fetchAll();
        $current = $pdo->prepare(
            "SELECT issuer_license_id FROM organization_license_registry
             WHERE organization_id=:organization_id AND assigned_user_id=:user_id
               AND license_type='user' AND status='active' ORDER BY id DESC LIMIT 1"
        );
        $current->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
        $currentLicenseId = $current->fetchColumn();
        echo json_encode(['currentLicenseId' => is_string($currentLicenseId) ? $currentLicenseId : null, 'licenses' => array_map(static fn (array $row): array => [
            'id' => $row['issuer_license_id'],
            'type' => $row['license_type'],
            'role' => $row['license_role'],
            'email' => $row['assigned_email'],
            'status' => $row['status'],
            'source' => $row['source'],
            'expiresAt' => $row['expires_at'],
            'lastOnlineCheckAt' => $row['last_online_check_at'],
        ], $licenses)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$isManager) {
        throw new RuntimeException('Accès gestionnaire requis.', 403);
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    (new RateLimiter($pdo))->assertAllowed(
        'assign_license',
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $organizationId,
        30,
        3600
    );
    if (is_array($input) && isset($input['licenseToken'])) {
        $result = (new ManualLicenseRegistrar($pdo, new LicenseTokenVerifier()))->register(
            (int) $organizationId,
            (int) $userId,
            (string) $input['licenseToken']
        );
        http_response_code(201);
        echo json_encode([
            'message' => 'Licence vérifiée et enregistrée dans votre organisation.',
            'license' => $result,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $memberId = filter_var(is_array($input) ? ($input['memberId'] ?? null) : null, FILTER_VALIDATE_INT);
    if (!$memberId) {
        throw new RuntimeException('Membre invalide.');
    }
    $manager = new MemberManager($pdo, new TransactionalMailer($services['mailer']));
    $result = $manager->assignUserLicense((int)$organizationId, (int)$memberId);
    http_response_code(201);
    echo json_encode([
        'message'=>'Licence affectée et envoyée par e-mail.',
        'licenseNumber'=>$result['licenseNumber'],
        'reused'=>$result['reused'],
    ],JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['message'=>'Données JSON invalides.'],JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $status = $exception->getCode() === 403 ? 403
        : (str_starts_with($exception->getMessage(), 'Trop de tentatives') ? 429 : 422);
    http_response_code($status);
    echo json_encode(['message'=>$exception->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['message'=>'Une erreur interne empêche l’affectation de la licence.'],JSON_UNESCAPED_UNICODE);
}
