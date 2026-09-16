<?php

declare(strict_types=1);

use AlpesEx\Portal\Commerce\DraftOrder;
use AlpesEx\Portal\Database;
use AlpesEx\Portal\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try{
    session_name('ALPESEXSESSID');session_set_cookie_params(['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
    $userId=filter_var($_SESSION['user_id']??null,FILTER_VALIDATE_INT);
    $organizationId=filter_var($_SESSION['organization_id']??null,FILTER_VALIDATE_INT);
    if(!$userId||!$organizationId||($_SESSION['role']??'')!=='manager')throw new RuntimeException('Accès gestionnaire requis.',403);
    $appDirectory=getenv('ALPESEX_APP_DIR')?:dirname(__DIR__,3).'/app';
    $services=require $appDirectory.'/bootstrap.php';$pdo=Database::connect($services['config']);$draft=new DraftOrder($pdo);
    $method=$_SERVER['REQUEST_METHOD']??'';
    if($method==='POST'){
        $input=json_decode(file_get_contents('php://input')?:'',true,32,JSON_THROW_ON_ERROR);
        if(!is_array($input))throw new RuntimeException('Corps de requête invalide.');
        (new RateLimiter($pdo))->assertAllowed('create_order',(string)($_SERVER['REMOTE_ADDR']??'unknown').'|'.$organizationId,30,3600);
        $id=$draft->create((int)$organizationId,(int)$userId,$input);
        http_response_code(201);echo json_encode(['id'=>$id,'redirect'=>'commande.html?id='.$id],JSON_UNESCAPED_UNICODE);exit;
    }
    if($method==='GET'){
        echo json_encode($draft->get((int)$organizationId,(string)($_GET['id']??'')),JSON_UNESCAPED_UNICODE);exit;
    }
    http_response_code(405);header('Allow: GET, POST');echo json_encode(['message'=>'Méthode non autorisée.'],JSON_UNESCAPED_UNICODE);
}catch(JsonException){http_response_code(400);echo json_encode(['message'=>'Données JSON invalides.'],JSON_UNESCAPED_UNICODE);
}catch(RuntimeException $exception){$code=$exception->getCode()===403?403:422;http_response_code($code);echo json_encode(['message'=>$exception->getMessage()],JSON_UNESCAPED_UNICODE);
}catch(Throwable $exception){http_response_code(500);echo json_encode(['message'=>'Une erreur interne empêche la préparation de la commande.'],JSON_UNESCAPED_UNICODE);}
