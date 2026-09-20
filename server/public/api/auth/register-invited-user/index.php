<?php

declare(strict_types=1);

use AlpesEx\Portal\Auth\RegisterInvitedUser;
use AlpesEx\Portal\Database;
use AlpesEx\Portal\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    http_response_code(405); header('Allow: POST');
    echo json_encode(['message'=>'Méthode non autorisée.'],JSON_UNESCAPED_UNICODE); exit;
}
try {
    $input=json_decode(file_get_contents('php://input')?:'',true,32,JSON_THROW_ON_ERROR);
    if(!is_array($input)) throw new RuntimeException('Corps de requête invalide.');
    $appDirectory=getenv('ALPESEX_APP_DIR')?:dirname(__DIR__,4).'/app';
    $services=require $appDirectory.'/bootstrap.php';
    $pdo=Database::connect($services['config']);
    (new RateLimiter($pdo))->assertAllowed('register_invited_user',(string)($_SERVER['REMOTE_ADDR']??'unknown'),10,3600);
    (new RegisterInvitedUser($pdo))->execute($input);
    http_response_code(201);
    echo json_encode(['message'=>'Votre compte est créé. Vous pouvez maintenant vous connecter.','redirect'=>'../compte/?invitation=accepted'],JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400); echo json_encode(['message'=>'Données JSON invalides.'],JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(str_starts_with($exception->getMessage(),'Trop de tentatives')?429:422);
    echo json_encode(['message'=>$exception->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500); echo json_encode(['message'=>'Une erreur interne empêche la création du compte.'],JSON_UNESCAPED_UNICODE);
}
