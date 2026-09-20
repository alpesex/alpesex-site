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
    $service=new ManagerLicenses($pdo,new LicenseIssuer(),new LicenseTokenVerifier());
    $_SESSION['manager_csrf']??=bin2hex(random_bytes(32));
    if ($method==='GET') {
        $action=$_GET['action']??'summary';
        if ($action==='summary') {
            $data=$service->summary((int)$org);
            // The tile counts distinct teammates or pending invitees with an assigned licence.
            $me=$pdo->prepare('SELECT email FROM users WHERE id=?');$me->execute([$user]);
            $ownEmail=strtolower((string)$me->fetchColumn());
            $data['licensedTeamCount']=count(array_filter($data['licensedEmails'],static fn($email)=>$email!==$ownEmail));
            unset($data['licensedEmails']);
            $data['csrf']=$_SESSION['manager_csrf'];$data['currentUserId']=(int)$user;
            echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;
        }
        if ($action==='devices') {
            $id=filter_var($_GET['memberId']??null,FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Utilisateur invalide.',422);
            echo json_encode(['devices'=>$service->devices((int)$org,(int)$id)],JSON_UNESCAPED_UNICODE);exit;
        }
        if ($action==='key') {
            $id=$_GET['licenseId']??null;
            if (!is_string($id)||$id===''||strlen($id)>100) throw new RuntimeException('Licence invalide.',422);
            echo json_encode(['licenseToken'=>$service->key((int)$org,$id)],JSON_UNESCAPED_UNICODE);exit;
        }
        throw new RuntimeException('Action inconnue.',404);
    }
    if (!hash_equals($_SESSION['manager_csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) throw new RuntimeException('Session expirée : rechargez la page.',403);
    $input=json_decode(file_get_contents('php://input')?:'',true,16,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Données invalides.',422);
    $action=$input['action']??'';
    if ($action==='assign'||$action==='release') {
        $id=$input['licenseId']??null;
        if (!is_string($id)||$id===''||strlen($id)>100) throw new RuntimeException('Licence invalide.',422);
        if ($action==='release') $service->release((int)$org,$id);
        else {
            $recipient=filter_var($input['recipientId']??null,FILTER_VALIDATE_INT);
            if (!$recipient || !in_array($input['recipientKind']??null,['member','invitation'],true)) throw new RuntimeException('Destinataire invalide.',422);
            $service->assign((int)$org,$id,$input['recipientKind'],(int)$recipient);
        }
    } elseif($action==='revoke') {
        $member=filter_var($input['memberId']??null,FILTER_VALIDATE_INT);$device=filter_var($input['deviceId']??null,FILTER_VALIDATE_INT);
        if (!$member||!$device) throw new RuntimeException('Appareil invalide.',422);
        $service->revoke((int)$org,(int)$member,(int)$device);
    } else throw new RuntimeException('Action inconnue.',422);
    echo json_encode(['message'=>$action==='assign'?'Licence attribuée.':($action==='release'?'Licence retirée et remise en stock.':'Appareil révoqué.')],JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);echo json_encode(['message'=>'Données invalides.']);
} catch (PDOException) {
    http_response_code(500);echo json_encode(['message'=>'Opération impossible. Réessayez ou contactez le support.'],JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(in_array($e->getCode(),[401,403,404,405,422],true)?$e->getCode():422);
    echo json_encode(['message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    http_response_code(500);echo json_encode(['message'=>'Opération impossible. Réessayez ou contactez le support.'],JSON_UNESCAPED_UNICODE);
}
