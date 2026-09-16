<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\Mail\TransactionalMailer;
use AlpesEx\Portal\Security\RateLimiter;
use AlpesEx\Portal\Team\MemberManager;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $managerId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $organizationId = filter_var($_SESSION['organization_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$managerId || !$organizationId || ($_SESSION['role'] ?? '') !== 'manager') {
        throw new RuntimeException('Accès gestionnaire requis.', 403);
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    $memberId = filter_var(is_array($input) ? ($input['memberId'] ?? null) : null, FILTER_VALIDATE_INT);
    if (!$memberId) {
        throw new RuntimeException('Membre invalide.');
    }

    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    (new RateLimiter($pdo))->assertAllowed(
        'assign_license',
        (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $organizationId,
        30,
        3600
    );
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
