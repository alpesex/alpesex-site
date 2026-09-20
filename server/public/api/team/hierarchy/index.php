<?php

declare(strict_types=1);
use AlpesEx\Portal\Database;
use AlpesEx\Portal\Licensing\LicenseIssuer;
use AlpesEx\Portal\Licensing\LicenseTokenVerifier;
use AlpesEx\Portal\Team\ManagerLicenses;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $method=$_SERVER['REQUEST_METHOD']??'';
    if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); throw new RuntimeException('Méthode non autorisée.',405); }
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $user=filter_var($_SESSION['user_id']??null,FILTER_VALIDATE_INT);
    $org=filter_var($_SESSION['organization_id']??null,FILTER_VALIDATE_INT);
    if (!$user || !$org) throw new RuntimeException('Connexion requise.',401);
    $appDirectory=getenv('ALPESEX_APP_DIR')?:dirname(__DIR__,4).'/app';
    $services=require $appDirectory.'/bootstrap.php';
    $pdo=Database::connect($services['config']);
    $check=$pdo->prepare("SELECT u.id FROM users u INNER JOIN organizations o ON o.id=u.organization_id WHERE u.id=? AND u.organization_id=? AND u.role='manager' AND u.status='active' AND o.status='active'");
    $check->execute([$user,$org]);
    if (!$check->fetchColumn()) throw new RuntimeException('Accès gestionnaire requis.',403);
    require_once $appDirectory.'/src/Team/TeamHierarchy.php';
    $service=new \AlpesEx\Portal\Team\TeamHierarchy($pdo);
    $_SESSION['hierarchy_csrf']??=bin2hex(random_bytes(32));
    if ($method==='GET') {
        echo json_encode($service->snapshot((int)$org)+['csrf'=>$_SESSION['hierarchy_csrf']],JSON_UNESCAPED_UNICODE);exit;
    }
    if (!hash_equals($_SESSION['hierarchy_csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) throw new RuntimeException('Session expirée : rechargez la page.',403);
    $input=json_decode(file_get_contents('php://input')?:'',true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input)||!is_int($input['revision']??null)||!is_array($input['links']??null)) throw new RuntimeException('Organigramme invalide.',422);
    $service->save((int)$org,(int)$user,$input['revision'],$input['links']);
    echo json_encode(['message'=>'Organigramme et droits enregistrés.'],JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);echo json_encode(['message'=>'Données invalides.']);
} catch (PDOException) {
    http_response_code(500);echo json_encode(['message'=>'Opération impossible. Réessayez ou contactez le support.'],JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(in_array($e->getCode(),[401,403,404,405,409,422],true)?$e->getCode():422);
    echo json_encode(['message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    http_response_code(500);echo json_encode(['message'=>'Opération impossible. Réessayez ou contactez le support.'],JSON_UNESCAPED_UNICODE);
}
