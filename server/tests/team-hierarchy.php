<?php
declare(strict_types=1);
require __DIR__.'/../src/Team/TeamHierarchy.php';
require __DIR__.'/../src/Application/ApplicationAccess.php';
class HierarchyTestPDO extends PDO {
 public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
function ensureHierarchy(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function rejectHierarchy(callable $call,int $code):void{try{$call();}catch(RuntimeException $e){ensureHierarchy($e->getCode()===$code,'Wrong rejection');return;}throw new RuntimeException('Unauthorized operation allowed');}
$db=new HierarchyTestPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));
$db->exec("CREATE TABLE organizations(id INTEGER PRIMARY KEY,status);INSERT INTO organizations VALUES(1,'active'),(2,'active');
 CREATE TABLE users(id INTEGER PRIMARY KEY,organization_id INTEGER,first_name,last_name,email,role,status);
 CREATE TABLE organization_license_registry(id INTEGER PRIMARY KEY,organization_id INTEGER,issuer_license_id,license_type,license_role,assigned_user_id INTEGER,status,expires_at,parent_master_license_id);
 CREATE TABLE organization_team_hierarchy(organization_id INTEGER,user_id INTEGER,parent_user_id INTEGER,access_mode,updated_by_user_id INTEGER,PRIMARY KEY(organization_id,user_id));
 CREATE TABLE organization_team_hierarchy_versions(organization_id INTEGER PRIMARY KEY,revision INTEGER);
 INSERT INTO users VALUES(1,1,'Owner','One','owner@example.test','manager','active'),(2,1,'Director','Two','direction@example.test','user','active'),(3,1,'Manager','Three','m1@example.test','user','active'),(4,1,'Manager','Four','m2@example.test','user','active'),(5,1,'User','Five','u1@example.test','user','active'),(6,1,'User','Six','u2@example.test','user','active'),(7,2,'Other','Seven','other@example.test','manager','active');
 INSERT INTO organization_license_registry VALUES(1,1,'master','master',NULL,NULL,'active',NULL,NULL)");
$q=$db->prepare("INSERT INTO organization_license_registry VALUES(?,1,?,'user',?,?,'active',NULL,'master')");
foreach([2=>'direction',3=>'manager',4=>'manager',5=>'user',6=>'user'] as $id=>$role)$q->execute([$id,'seat-'.$id,$role,$id]);
$service=new AlpesEx\Portal\Team\TeamHierarchy($db);$access=new AlpesEx\Portal\Application\ApplicationAccess($db);
$link=fn($user,$parent,$mode='editor')=>['userId'=>$user,'parentId'=>$parent,'accessMode'=>$mode];
$links=[$link(3,2,'viewer'),$link(4,2,'viewer'),$link(5,3),$link(6,4,'viewer')];
ensureHierarchy($service->managerAccess(1,3,5)==='none','No implicit inviter access');
rejectHierarchy(fn()=>$service->save(1,3,0,$links),403);
rejectHierarchy(fn()=>$service->save(1,7,0,$links),403);
$service->save(1,1,0,$links);
$m1=['id'=>3,'organizationId'=>1,'role'=>'manager'];$m2=['id'=>4,'organizationId'=>1,'role'=>'manager'];
$p1=['organization_id'=>1,'owner_user_id'=>5,'team_manager_id'=>4];$p2=['organization_id'=>1,'owner_user_id'=>6];
ensureHierarchy($access->projectAccess($m1,$p1)==='editor','Team editing');
ensureHierarchy($access->projectAccess($m2,$p1)==='none','Forged legacy manager ignored');
ensureHierarchy($access->projectAccess($m2,$p2)==='viewer','Read-only team');
ensureHierarchy($access->projectAccess(['id'=>2,'organizationId'=>1,'role'=>'direction'],$p1)==='viewer','Direction read-only');
ensureHierarchy($access->projectAccess(['id'=>5,'organizationId'=>1,'role'=>'user'],$p1)==='editor','Own project');
ensureHierarchy($access->projectAccess(['id'=>6,'organizationId'=>1,'role'=>'user'],$p1)==='none','Other user');
ensureHierarchy($access->projectAccess(['id'=>7,'organizationId'=>2,'role'=>'direction'],$p1)==='none','Organization isolation');
rejectHierarchy(fn()=>$service->save(1,1,0,$links),409);
foreach([[$link(3,3)],[$link(5,7)],[$link(3,4)],[$link(2,3)],[$link(5,3),$link(5,4)]] as $invalid)rejectHierarchy(fn()=>$service->save(1,1,1,$invalid),422);
ensureHierarchy($service->managerAccess(1,3,5)==='editor','Invalid changes rolled back');
$service->save(1,1,1,[$link(5,4)]);
ensureHierarchy($service->managerAccess(1,3,5)==='none'&&$service->managerAccess(1,4,5)==='editor','Move removes prior manager');
$db->exec("UPDATE organization_license_registry SET license_role='user' WHERE id=4");
ensureHierarchy($service->managerAccess(1,4,5)==='none','Downgrade removes access');
$db->exec("UPDATE organization_license_registry SET license_role='manager' WHERE id=4;UPDATE organization_license_registry SET expires_at='2000-01-01' WHERE id=5");
ensureHierarchy($service->managerAccess(1,4,5)==='none','Expired user licence');
$db->exec("UPDATE organization_license_registry SET expires_at=NULL WHERE id=5;UPDATE organization_license_registry SET status='suspended' WHERE id=1");
ensureHierarchy($service->managerAccess(1,4,5)==='none','Inactive Master');
echo "OK: hierarchy validation, roles, read/write isolation, own projects, cross-org denial, reassignment, licence changes, stale writes.\n";
