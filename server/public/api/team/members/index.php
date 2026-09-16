<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;
use AlpesEx\Portal\Mail\TransactionalMailer;
use AlpesEx\Portal\Licensing\LicenseIssuer;
use AlpesEx\Portal\Team\MemberManager;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
    http_response_code(405);
    header('Allow: DELETE');
    echo json_encode(['message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $managerId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $organizationId = filter_var($_SESSION['organization_id'] ?? null, FILTER_VALIDATE_INT);
    $memberId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$managerId || !$organizationId || ($_SESSION['role'] ?? '') !== 'manager') {
        throw new RuntimeException('Accès gestionnaire requis.', 403);
    }
    if (!$memberId) {
        throw new RuntimeException('Membre invalide.');
    }

    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    $manager = new MemberManager($pdo, new TransactionalMailer($services['mailer']), new LicenseIssuer($services['config']));
    $manager->removeMember((int)$organizationId, (int)$managerId, (int)$memberId);
    echo json_encode(['message'=>'Le membre a été retiré et sa licence est de nouveau disponible.'],JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code($exception->getCode() === 403 ? 403 : 422);
    echo json_encode(['message'=>$exception->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['message'=>'Une erreur interne empêche la suppression du membre.'],JSON_UNESCAPED_UNICODE);
}
