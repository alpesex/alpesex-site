<?php

declare(strict_types=1);

use AlpesEx\Portal\Auth\InviteMember;
use AlpesEx\Portal\Database;
use AlpesEx\Portal\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); header('Allow: POST');
    echo json_encode(['message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE); exit;
}
try {
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $organizationId = filter_var($_SESSION['organization_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || !$organizationId || ($_SESSION['role'] ?? '') !== 'manager') {
        throw new RuntimeException('Accès gestionnaire requis.', 403);
    }
    $input = json_decode(file_get_contents('php://input') ?: '', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Corps de requête invalide.');
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    (new RateLimiter($pdo))->assertAllowed('invite_member',(string)($_SERVER['REMOTE_ADDR']??'unknown').'|'.$organizationId,20,3600);
    $result=(new InviteMember($pdo,$services['config'],$services['mailer']))->execute((int)$organizationId,(int)$userId,$input);
    http_response_code(201);
    if ($result['existingAccount']) {
        $message=$result['reactivated']
            ? 'Compte réactivé et invitation envoyée. L’utilisateur peut se connecter avec son mot de passe existant.'
            : ($result['sameOrganization']
                ? 'Invitation envoyée. Cet utilisateur possède déjà un compte actif dans votre organisation.'
                : 'Invitation envoyée. Cet utilisateur possède déjà un compte ALPES’Ex rattaché à une autre organisation.');
    } else {
        $message='Invitation envoyée avec succès.';
    }
    echo json_encode([
        'message'=>$message,
        'existingAccount'=>$result['existingAccount'],
        'sameOrganization'=>$result['sameOrganization'],
        'reactivated'=>$result['reactivated'],
    ],JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400); echo json_encode(['message'=>'Données JSON invalides.'],JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $code=$exception->getCode()===403?403:(str_starts_with($exception->getMessage(),'Trop de tentatives')?429:422);
    http_response_code($code); echo json_encode(['message'=>$exception->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500); echo json_encode(['message'=>'Une erreur interne empêche l’envoi de l’invitation.'],JSON_UNESCAPED_UNICODE);
}
