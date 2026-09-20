<?php

declare(strict_types=1);
use AlpesEx\Portal\Database;
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET') {header('Allow: GET');throw new RuntimeException('Méthode non autorisée.',405);}
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
    $user=filter_var($_SESSION['user_id']??null,FILTER_VALIDATE_INT);$org=filter_var($_SESSION['organization_id']??null,FILTER_VALIDATE_INT);
    if (!$user||!$org) throw new RuntimeException('Connexion requise.',401);
    $appDirectory=getenv('ALPESEX_APP_DIR')?:dirname(__DIR__,4).'/app';
    $services=require $appDirectory.'/bootstrap.php';$pdo=Database::connect($services['config']);
    $q=$pdo->prepare("SELECT u.id FROM users u INNER JOIN organizations o ON o.id=u.organization_id WHERE u.id=? AND u.organization_id=? AND u.role='manager' AND u.status='active' AND o.status='active'");$q->execute([$user,$org]);
    if (!$q->fetchColumn()) throw new RuntimeException('Accès gestionnaire requis.',403);
    $file=$_ENV['ALPESEX_IT_INSTALLER']??getenv('ALPESEX_IT_INSTALLER')?:'/home/www/private/downloads/ASM-IT-Setup-V0.5.11-x64-FINAL.exe';
    if (!is_file($file)||!is_readable($file)||filesize($file)<=0) throw new RuntimeException('L’installateur IT-ASM sera disponible prochainement. Contactez contact@alpes-ex.fr.',503);
    session_write_close();header('Content-Type: application/vnd.microsoft.portable-executable');header('Content-Disposition: attachment; filename="ASM-IT-Setup.exe"');header('Content-Length: '.filesize($file));readfile($file);
} catch(RuntimeException $e) {
    http_response_code($e->getCode()?:500);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();
} catch(Throwable) {http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Téléchargement indisponible.';}
