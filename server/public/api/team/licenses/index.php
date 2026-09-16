<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\Mail\TransactionalMailer;
use AlpesEx\Portal\Licensing\LicenseIssuer;
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
        $sql = "SELECT l.license_number,l.license_type,l.status,l.signed_token,l.expires_at,
                       u.email AS assigned_email
                FROM organization_licenses l
                LEFT JOIN users u ON u.id=l.assigned_user_id
                WHERE l.organization_id=:organization_id";
        $parameters = ['organization_id' => $organizationId];
        if (!$isManager) {
            $sql .= ' AND l.assigned_user_id=:user_id';
            $parameters['user_id'] = $userId;
        }
        $sql .= ' ORDER BY FIELD(l.license_type,\'master\',\'manager\',\'direction\',\'user\'),l.id';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $licenses = array_map(static fn (array $row): array => [
            'number' => $row['license_number'],
            'type' => $row['license_type'],
            'status' => $row['status'],
            'token' => $row['signed_token'],
            'assignedEmail' => $row['assigned_email'],
            'expiresAt' => $row['expires_at'],
        ], $statement->fetchAll());
        echo json_encode(['licenses' => $licenses, 'managerView' => $isManager], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$isManager) {
        throw new RuntimeException('Accès gestionnaire requis.', 403);
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    $memberId = filter_var(is_array($input) ? ($input['memberId'] ?? null) : null, FILTER_VALIDATE_INT);
    $licenseType = is_array($input) ? (string) ($input['licenseType'] ?? 'user') : 'user';
    if (!$memberId) {
        throw new RuntimeException('Membre invalide.');
    }

    (new RateLimiter($pdo))->assertAllowed(
        'assign_license',
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $organizationId,
        30,
        3600
    );
    $manager = new MemberManager($pdo, new TransactionalMailer($services['mailer']), new LicenseIssuer($services['config']));
    $result = $manager->assignUserLicense((int)$organizationId, (int)$memberId, $licenseType);
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
