<?php
declare(strict_types=1);
require __DIR__.'/../../scripts/create-trial-account.php';
require __DIR__.'/../src/Auth/Login.php';
function checkTrial(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE organizations(id INTEGER PRIMARY KEY AUTOINCREMENT,name,legal_form,country,billing_address_1,postal_code,city,billing_email,terms_accepted_at,status)');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,organization_id,email UNIQUE,password_hash,first_name,last_name,role,status,email_verified_at)');
$pdo->exec('CREATE TABLE organization_license_registry(id INTEGER PRIMARY KEY AUTOINCREMENT,organization_id,issuer_license_id UNIQUE,license_type,license_role,parent_master_license_id,token_hash UNIQUE,license_token,status,source,expires_at,created_by_user_id,assigned_user_id DEFAULT NULL,assigned_email DEFAULT NULL)');
$pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$public=sodium_crypto_sign_publickey($pair);
$decode=static fn($s)=>base64_decode(strtr($s,'-_','+/'),true);
$verify=static function($token)use($public,$decode){[$body,$signature]=explode('.',$token);checkTrial(sodium_crypto_sign_verify_detached($decode($signature),'ASM-LICENSE-V1.'.$body,$public),'Signature');return json_decode($decode($body),true,512,JSON_THROW_ON_ERROR);};
$template=['schema'=>2,'type'=>'master','id'=>'fixture','plan'=>'trial','organizationId'=>'fixture-org','company'=>'fixture','authorityId'=>'alpesex-cpmp-production-2026','issuedAt'=>1,'expiresAt'=>2];
$directory=sys_get_temp_dir().'/trial-test-'.bin2hex(random_bytes(8));
try {
 $receipt=createTrial($pdo,'test@example.test',$template,$secret,$verify,$directory);
 $text=file_get_contents($receipt);preg_match('/Mot de passe : ([a-f0-9]+)/',$text,$m);
 $login=(new AlpesEx\Portal\Auth\Login($pdo))->execute(['email'=>'test@example.test','password'=>$m[1],'profileType'=>'manager']);
 checkTrial($login['role']==='manager','Manager login');
 checkTrial((fileperms($receipt)&0777)===0600,'Private receipt');
 $rows=$pdo->query('SELECT * FROM organization_license_registry')->fetchAll();checkTrial(count($rows)===7,'Seven licenses');
 $roles=[];$master=$verify($rows[0]['license_token']);
 foreach($rows as $row){$c=$verify($row['license_token']);checkTrial($c['schema']===2,'Schema 2');checkTrial($c['expiresAt']-$c['issuedAt']===30*86400*1000,'30 days');checkTrial($c['organizationId']===$master['organizationId'],'Same org');
 if($row['license_type']==='user'){$roles[]=$c['role'];checkTrial($c['masterId']===$master['id']&&$c['maxDevices']===3,'Parent and devices');checkTrial($row['status']==='available'&&$row['assigned_user_id']===null&&$row['assigned_email']===null,'Unassigned stock');}}
 sort($roles);checkTrial($roles===['direction','manager','manager','user','user','user'],'Requested roles');
 try{createTrial($pdo,'test@example.test',$template,$secret,$verify,$directory);throw new LogicException('Duplicate accepted');}catch(RuntimeException $e){checkTrial(str_contains($e->getMessage(),'existe déjà'),'Duplicate rejected');}
 $pdo->exec("CREATE TRIGGER fail_registry BEFORE INSERT ON organization_license_registry BEGIN SELECT RAISE(ABORT,'forced test failure'); END");
 try{createTrial($pdo,'other@example.test',$template,$secret,$verify,$directory);throw new LogicException('Failure ignored');}catch(PDOException $e){}
 checkTrial((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'Rollback user');checkTrial((int)$pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn()===1,'Rollback organization');checkTrial(count(glob($directory.'/*'))===1,'Rollback receipt');
 echo "OK: signatures, 30 days, roles, stock, manager login, private receipt, duplicate rejection, rollback.\n";
}finally{foreach(glob($directory.'/*')?:[] as $f)unlink($f);if(is_dir($directory))rmdir($directory);}
