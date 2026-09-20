<?php
declare(strict_types=1);
namespace AlpesEx\Portal\Team;
use PDO;
use RuntimeException;
use Throwable;

final class TeamHierarchy {
    public function __construct(private readonly PDO $pdo) {}
    public function snapshot(int $org): array {
        $revision=$this->pdo->prepare('SELECT revision FROM organization_team_hierarchy_versions WHERE organization_id=?');$revision->execute([$org]);
        $version=(int)($revision->fetchColumn()?:0);
        $q=$this->pdo->prepare("SELECT u.id,u.first_name AS firstName,u.last_name AS lastName,u.email,
            h.parent_user_id AS parentId,h.access_mode AS accessMode,
            CASE WHEN r.id IS NOT NULL AND p.id IS NOT NULL THEN r.license_role ELSE NULL END AS licenseRole
            FROM users u
            LEFT JOIN organization_team_hierarchy h ON h.organization_id=u.organization_id AND h.user_id=u.id
            LEFT JOIN organization_license_registry r ON r.organization_id=u.organization_id AND r.assigned_user_id=u.id
              AND r.license_type='user' AND r.status='active' AND (r.expires_at IS NULL OR r.expires_at>UTC_TIMESTAMP())
            LEFT JOIN organization_license_registry p ON p.organization_id=r.organization_id AND p.issuer_license_id=r.parent_master_license_id
              AND p.license_type='master' AND p.status='active' AND (p.expires_at IS NULL OR p.expires_at>UTC_TIMESTAMP())
            WHERE u.organization_id=? AND u.status='active' ORDER BY u.last_name,u.first_name,u.id");
        $q->execute([$org]);$members=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $id=(int)$row['id'];
            // Multiple active seats are an inconsistent state: never choose the most privileged role.
            if(isset($members[$id])){$members[$id]['licenseRole']=null;continue;}
            $row['id']=$id;$row['parentId']=$row['parentId']===null?null:(int)$row['parentId'];
            $row['accessMode']=$row['accessMode']??'viewer';$members[$id]=$row;
        }
        return ['members'=>array_values($members),'revision'=>$version];
    }
    public function save(int $org,int $actor,int $revision,array $links):void {
        if(count($links)>2000)throw new RuntimeException('Organigramme trop volumineux.',422);
        $this->pdo->beginTransaction();
        try{
            $lock=$this->pdo->prepare("SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE");$lock->execute([$org]);
            if(!$lock->fetchColumn())throw new RuntimeException('Organisation inactive.',403);
            $auth=$this->pdo->prepare("SELECT id FROM users WHERE id=? AND organization_id=? AND role='manager' AND status='active'");$auth->execute([$actor,$org]);
            if(!$auth->fetchColumn())throw new RuntimeException('Accès gestionnaire requis.',403);
            $snapshot=$this->snapshot($org);
            if($snapshot['revision']!==$revision)throw new RuntimeException('L’organigramme a changé. Rechargez-le avant de modifier.',409);
            $people=array_column($snapshot['members'],null,'id');$seen=[];
            foreach($links as $link){
                if(!is_array($link))throw new RuntimeException('Rattachement invalide.',422);
                $child=$link['userId']??null;$parent=$link['parentId']??null;$mode=$link['accessMode']??null;
                if(!is_int($child)||!is_int($parent)||$child===$parent||isset($seen[$child])||!isset($people[$child],$people[$parent])||!in_array($mode,['viewer','editor'],true))throw new RuntimeException('Rattachement invalide.',422);
                $childRole=$people[$child]['licenseRole'];$parentRole=$people[$parent]['licenseRole'];
                if(!(($childRole==='user'&&$parentRole==='manager')||($childRole==='manager'&&$parentRole==='direction'&&$mode==='viewer')))throw new RuntimeException('Rattachez un utilisateur à un Manager, et un Manager à une Direction disposant d’une licence active.',422);
                $seen[$child]=true;
            }
            // Fixed Direction > Manager > User levels make cycles impossible.
            $delete=$this->pdo->prepare('DELETE FROM organization_team_hierarchy WHERE organization_id=?');$delete->execute([$org]);
            $insert=$this->pdo->prepare('INSERT INTO organization_team_hierarchy (organization_id,user_id,parent_user_id,access_mode,updated_by_user_id) VALUES (?,?,?,?,?)');
            foreach($links as $link)$insert->execute([$org,$link['userId'],$link['parentId'],$link['accessMode'],$actor]);
            if($revision===0){$q=$this->pdo->prepare('INSERT INTO organization_team_hierarchy_versions (organization_id,revision) VALUES (?,1)');$q->execute([$org]);}
            else{$q=$this->pdo->prepare('UPDATE organization_team_hierarchy_versions SET revision=revision+1 WHERE organization_id=?');$q->execute([$org]);}
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function managerAccess(int $org,int $manager,int $owner):string {
        // Resolve current hierarchy and current licences on every request, including direct project/document requests.
        $people=array_column($this->snapshot($org)['members'],null,'id');
        $m=$people[$manager]??null;$u=$people[$owner]??null;
        if(!$m||!$u||$m['licenseRole']!=='manager'||$u['licenseRole']!=='user'||$u['parentId']!==$manager)return 'none';
        return in_array($u['accessMode'],['viewer','editor'],true)?$u['accessMode']:'none';
    }
}
