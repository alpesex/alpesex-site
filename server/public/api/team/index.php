<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405); header('Allow: GET');
    echo json_encode(['message'=>'Méthode non autorisée.'],JSON_UNESCAPED_UNICODE); exit;
}
try {
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $organizationId=filter_var($_SESSION['organization_id']??null,FILTER_VALIDATE_INT);
    if (!$organizationId || ($_SESSION['role']??'')!=='manager') throw new RuntimeException('Accès gestionnaire requis.');
    $appDirectory=getenv('ALPESEX_APP_DIR')?:dirname(__DIR__,3).'/app';
    $services=require $appDirectory.'/bootstrap.php';
    $pdo=Database::connect($services['config']);
    $members=$pdo->prepare('SELECT id,email,first_name,last_name,role,status,created_at FROM users WHERE organization_id=:organization_id ORDER BY role ASC,last_name ASC,first_name ASC');
    $members->execute(['organization_id'=>$organizationId]);
    $invitations=$pdo->prepare("SELECT id,email,first_name,last_name,intended_license,expires_at,created_at FROM organization_invitations WHERE organization_id=:organization_id AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY created_at DESC");
    $invitations->execute(['organization_id'=>$organizationId]);
    echo json_encode(['members'=>array_map(static fn(array $row):array=>['id'=>(int)$row['id'],'email'=>$row['email'],'firstName'=>$row['first_name'],'lastName'=>$row['last_name'],'role'=>$row['role'],'status'=>$row['status'],'createdAt'=>$row['created_at']],$members->fetchAll()),'invitations'=>array_map(static fn(array $row):array=>['id'=>(int)$row['id'],'email'=>$row['email'],'firstName'=>$row['first_name'],'lastName'=>$row['last_name'],'licenseType'=>$row['intended_license'],'expiresAt'=>$row['expires_at'],'createdAt'=>$row['created_at']],$invitations->fetchAll())],JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(403); echo json_encode(['message'=>$exception->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500); echo json_encode(['message'=>'Une erreur interne empêche le chargement de l’équipe.'],JSON_UNESCAPED_UNICODE);
}
