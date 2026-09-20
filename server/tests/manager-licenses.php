<?php
// Test fixtures only: generated key, fake organizations, in-memory database.
declare(strict_types=1);
require __DIR__.'/../src/Licensing/LicenseIssuer.php';
require __DIR__.'/../src/Licensing/LicenseTokenVerifier.php';
require __DIR__.'/../src/Team/ManagerLicenses.php';
require __DIR__.'/../src/Auth/RegisterInvitedUser.php';
require __DIR__.'/../src/Auth/Login.php';
use AlpesEx\Portal\Licensing\LicenseIssuer;
use AlpesEx\Portal\Licensing\LicenseTokenVerifier;
use AlpesEx\Portal\Team\ManagerLicenses;
use AlpesEx\Portal\Auth\RegisterInvitedUser;
// SQLite is used for isolated portable tests. Production's organization lock is MySQL FOR UPDATE.
class TestDB extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $query=str_replace(' FOR UPDATE','',$query);
        if(str_starts_with($query,'UPDATE organization_licenses l INNER JOIN')) $query="UPDATE organization_licenses SET assigned_user_id=?,status='assigned' WHERE EXISTS (SELECT 1 FROM organization_license_registry r WHERE r.organization_id=organization_licenses.organization_id AND r.issuer_license_id=organization_licenses.issuer_license_id AND r.organization_id=? AND r.assigned_user_id=? AND r.status='active')";
        return parent::prepare($query,$options);
    }
}
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function blocked(callable $action): void {try{$action();}catch(RuntimeException){return;}throw new RuntimeException('Expected rejection');}
$db=new TestDB('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));
$db->sqliteCreateFunction('CONCAT',fn(...$args)=>implode('',$args));
$db->sqliteCreateFunction('SHA2',fn($value,$bits)=>hash('sha256',$value));
$db->exec("CREATE TABLE organizations(id INTEGER PRIMARY KEY,status TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,organization_id INTEGER,email TEXT,first_name TEXT,last_name TEXT,role TEXT,status TEXT,team_manager_id INTEGER,password_hash TEXT,email_verified_at TEXT);
CREATE TABLE organization_invitations(id INTEGER PRIMARY KEY,organization_id INTEGER,invited_by_user_id INTEGER,email TEXT,first_name TEXT,last_name TEXT,intended_license TEXT,expires_at TEXT,created_at TEXT,accepted_at TEXT,revoked_at TEXT,token_hash TEXT);
CREATE TABLE organization_license_registry(id INTEGER PRIMARY KEY,organization_id INTEGER,issuer_license_id TEXT UNIQUE,license_type TEXT,license_role TEXT,parent_master_license_id TEXT,assigned_user_id INTEGER,assigned_email TEXT,status TEXT,license_token TEXT,token_hash TEXT,expires_at TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE organization_licenses(id INTEGER PRIMARY KEY,organization_id INTEGER,issuer_license_id TEXT,assigned_user_id INTEGER,license_token TEXT,status TEXT,assigned_at TEXT,released_at TEXT);
CREATE TABLE application_devices(id INTEGER PRIMARY KEY,organization_id INTEGER,user_id INTEGER,license_registry_id INTEGER,device_identifier TEXT,device_name TEXT,platform TEXT,status TEXT,last_seen_at TEXT,revoked_at TEXT);
INSERT INTO organizations VALUES(1,'active'),(2,'active');
INSERT INTO users(id,organization_id,email,first_name,last_name,role,status) VALUES(1,1,'owner@example.test','Owner','One','manager','active'),(2,1,'alice@example.test','Alice','One','user','active'),(3,2,'other@example.test','Other','Two','user','active'),(4,1,'bob@example.test','Bob','One','user','active');
INSERT INTO organization_license_registry(id,organization_id,issuer_license_id,license_type,status) VALUES(1,1,'master-1','master','active'),(2,2,'master-2','master','active');");
$seed=random_bytes(32);$der=hex2bin('302e020100300506032b657004220420').$seed;
$key=tempnam(sys_get_temp_dir(),'asm-fixture-');file_put_contents($key,"-----BEGIN PRIVATE KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PRIVATE KEY-----\n");
try {
 $issuer=new LicenseIssuer($key);$verifier=new LicenseTokenVerifier($key);$service=new ManagerLicenses($db,$issuer,$verifier);
 $claims=['schema'=>2,'type'=>'user','id'=>'seat-1','masterId'=>'master-1','organizationId'=>'org-1','authorityId'=>'fixture','email'=>'old@example.test','role'=>'direction','expiresAt'=>null,'maxDevices'=>3,'issuedAt'=>1];
 $token=$issuer->reassignUser($claims,'old@example.test');
 $q=$db->prepare("INSERT INTO organization_license_registry(id,organization_id,issuer_license_id,license_type,license_role,parent_master_license_id,status,license_token,token_hash) VALUES(10,1,'seat-1','user','direction','master-1','available',?,?)");$q->execute([$token,hash('sha256',$token)]);
 $db->exec("INSERT INTO organization_licenses(id,organization_id,issuer_license_id,status) VALUES(10,1,'seat-1','available')");
 check($service->summary(1)['licenses'][0]['id']!==null,'Summary');
 blocked(fn()=>$service->assign(2,'seat-1','member',3));
 blocked(fn()=>$service->assign(1,'seat-1','member',3));
 $service->assign(1,'seat-1','member',2);
 $issued=$service->key(1,'seat-1');$decoded=$verifier->verify($issued);
 check($decoded['email']==='alice@example.test'&&$decoded['role']==='direction'&&$decoded['schema']===2,'Signed reassignment must preserve role/schema');
 check($issued!==$token,'Old token must be replaced');
 blocked(fn()=>$service->key(2,'seat-1'));
 blocked(fn()=>$service->assign(1,'seat-1','member',4));
 $db->exec("INSERT INTO application_devices VALUES(1,1,2,10,'old-hardware','Alice PC','windows','active','2026-09-20 10:00:00',NULL),(2,2,3,20,'other-hardware','Other PC','windows','active','2026-09-20 10:00:00',NULL)");
 blocked(fn()=>$service->devices(1,3));blocked(fn()=>$service->revoke(1,2,2));
 $service->release(1,'seat-1');
 check($db->query('SELECT status FROM application_devices WHERE id=1')->fetchColumn()==='revoked','Release revokes devices');
 check($db->query('SELECT device_identifier FROM application_devices WHERE id=1')->fetchColumn()!=='old-hardware','New owner can activate the same hardware');
 check($db->query('SELECT assigned_user_id FROM organization_license_registry WHERE id=10')->fetchColumn()===null,'Release clears owner');
 blocked(fn()=>$service->key(1,'seat-1'));
 $invitationToken=bin2hex(random_bytes(32));
 $q=$db->prepare("INSERT INTO organization_invitations(id,organization_id,invited_by_user_id,email,first_name,last_name,intended_license,expires_at,token_hash) VALUES(1,1,1,'invite@example.test','Invite','One','user','2099-01-01',?)");$q->execute([hash('sha256',$invitationToken)]);
 $service->assign(1,'seat-1','invitation',1);
 check($db->query('SELECT status FROM organization_license_registry WHERE id=10')->fetchColumn()==='reserved','Pending invitation reserves seat');
 check(in_array('invite@example.test',$service->summary(1)['licensedEmails'],true),'Pending reserved recipient counted');
 (new RegisterInvitedUser($db))->execute(['invitationToken'=>$invitationToken,'email'=>'invite@example.test','password'=>'Fixture-only-password!','terms'=>true]);
 $row=$db->query('SELECT status,assigned_user_id FROM organization_license_registry WHERE id=10')->fetch();
 check($row['status']==='active'&&(int)$row['assigned_user_id']>4,'Accept invitation binds reserved licence');
 check($db->query('SELECT role FROM users WHERE id='.(int)$row['assigned_user_id'])->fetchColumn()==='user','Invitation must not grant portal admin rights');
 $db->exec("UPDATE organization_license_registry SET license_role='manager' WHERE id=10");
 $logged=(new \AlpesEx\Portal\Auth\Login($db))->execute(['email'=>'invite@example.test','password'=>'Fixture-only-password!','profileType'=>'manager']);
 check($logged['role']==='user','Manager licence login preserves non-administrative account role');
 $service->release(1,'seat-1');
 blocked(fn()=>(new \AlpesEx\Portal\Auth\Login($db))->execute(['email'=>'invite@example.test','password'=>'Fixture-only-password!','profileType'=>'manager']));
 $db->exec("UPDATE organization_license_registry SET expires_at='2000-01-01' WHERE id=10");blocked(fn()=>$service->assign(1,'seat-1','member',4));
 $db->exec("UPDATE organization_license_registry SET expires_at=NULL WHERE id=10;UPDATE organization_license_registry SET status='suspended' WHERE id=1");blocked(fn()=>$service->assign(1,'seat-1','member',4));
 check(!$db->inTransaction(),'Rejected operations roll back');
 echo "OK: signatures, scope isolation, assign/release, device revocation, invitation reservation/acceptance, expiry, suspended Master, rollback.\n";
}finally{unlink($key);}
