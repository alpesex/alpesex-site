<?php
declare(strict_types=1);
$services = require __DIR__ . '/bootstrap.php';
$pdo = AlpesEx\Portal\Database::connect($services['config']);
$pdo->exec("CREATE TABLE organizations (id BIGINT UNSIGNED PRIMARY KEY, status VARCHAR(32));
CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, organization_id BIGINT UNSIGNED, email VARCHAR(190), first_name VARCHAR(100),last_name VARCHAR(100),role VARCHAR(32),status VARCHAR(32),password_hash VARCHAR(255));
CREATE TABLE organization_license_registry (id BIGINT UNSIGNED PRIMARY KEY,organization_id BIGINT UNSIGNED,issuer_license_id VARCHAR(100),parent_master_license_id VARCHAR(100),license_type VARCHAR(32),license_role VARCHAR(32),status VARCHAR(32),expires_at DATETIME NULL,assigned_user_id BIGINT UNSIGNED,assigned_email VARCHAR(190),token_hash CHAR(64));
INSERT INTO organizations VALUES (1,'active'),(2,'active');
INSERT INTO users VALUES (1,1,'one@example.test','One','Test','user','active',''),(2,1,'manager@example.test','Manager','Test','manager','active',''),(3,1,'direction@example.test','Direction','Test','user','active',''),(4,2,'other@example.test','Other','Test','user','active','');");
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/012_create_mobile_application.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/013_application_project_deletion.sql'));
$pdo->exec('UPDATE users SET team_manager_id=2 WHERE id=1');
$insert=$pdo->prepare('INSERT INTO organization_license_registry (id,organization_id,issuer_license_id,parent_master_license_id,license_type,license_role,status,expires_at,assigned_user_id,assigned_email,token_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
foreach ([1,2] as $org) $insert->execute([10*$org,$org,'master-'.$org,null,'master','master','active',null,null,null,hash('sha256','master-'.$org)]);
$emails=[1=>'one',2=>'manager',3=>'direction',4=>'other'];
foreach ($emails as $id=>$name) {
    $org=$id===4?2:1;
    $insert->execute([100+$id,$org,'user-'.$id,'master-'.$org,'user',$id===3?'direction':($id===2?'manager':'user'),'active',null,$id,$name.'@example.test',hash('sha256','test-license-'.$id)]);
    $device=hash('sha256','device-'.$id);
    $statement=$pdo->prepare('INSERT INTO application_devices (organization_id,user_id,license_registry_id,device_identifier,device_name,platform) VALUES (?,?,?,?,?,?)');
    $statement->execute([$org,$id,100+$id,$device,'Test','web']);
    foreach (range(1,5) as $copy) {
        session_id('test'.$id.'copy'.$copy);
        session_start();
        $_SESSION=['user_id'=>$id,'organization_id'=>$org,'application_license_id'=>100+$id,'application_device'=>$device];
        session_write_close();
    }
}
echo "Disposable integration fixtures ready\n";
