<?php

declare(strict_types=1);
namespace AlpesEx\Portal\Team;

use AlpesEx\Portal\Licensing\LicenseIssuer;
use AlpesEx\Portal\Licensing\LicenseTokenVerifier;
use PDO;
use RuntimeException;
use Throwable;

final class ManagerLicenses
{
    public function __construct(private readonly PDO $pdo, private readonly LicenseIssuer $issuer, private readonly LicenseTokenVerifier $verifier) {}

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $query = $this->pdo->prepare($sql); $query->execute($params); return $query;
    }
    private function lockOrganization(int $org): void
    {
        if (!$this->query("SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE", [$org])->fetchColumn()) {
            throw new RuntimeException('Organisation inactive.');
        }
    }
    public function summary(int $org): array
    {
        $members = $this->query("SELECT id,email,first_name AS firstName,last_name AS lastName,role,status FROM users WHERE organization_id=? AND status<>'removed' ORDER BY last_name,first_name", [$org])->fetchAll(PDO::FETCH_ASSOC);
        $invitations = $this->query("SELECT id,email,first_name AS firstName,last_name AS lastName,intended_license AS licenseType,expires_at AS expiresAt FROM organization_invitations WHERE organization_id=? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY created_at DESC", [$org])->fetchAll(PDO::FETCH_ASSOC);
        $licenses = $this->query("SELECT r.issuer_license_id AS id,r.license_type AS type,r.license_role AS role,r.assigned_user_id AS memberId,r.assigned_email AS email,r.status,r.expires_at AS expiresAt,
            (r.status IN ('active','available','reserved') AND (r.expires_at IS NULL OR r.expires_at>UTC_TIMESTAMP()) AND p.status='active' AND (p.expires_at IS NULL OR p.expires_at>UTC_TIMESTAMP())) AS usable
            FROM organization_license_registry r LEFT JOIN organization_license_registry p ON p.organization_id=r.organization_id AND p.issuer_license_id=r.parent_master_license_id AND p.license_type='master'
            WHERE r.organization_id=? ORDER BY r.created_at DESC", [$org])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($licenses as &$license) {
            $license['memberId'] = $license['memberId'] === null ? null : (int)$license['memberId'];
            $license['usable'] = (bool)$license['usable'];
            $license['inStock'] = $license['type']==='user' && $license['memberId']===null && !$license['email'] && $license['usable'];
        }
        unset($license);
        // Count distinct recipients: accepted members + still-valid invitations, excluding the account owner.
        $licensed = [];
        foreach ($licenses as $license) if ($license['type']==='user' && $license['usable'] && $license['email']) {
            foreach (array_merge($members, $invitations) as $person) {
                if (strtolower($person['email'])===strtolower($license['email'])) $licensed[strtolower($person['email'])]=true;
            }
        }
        return compact('members','invitations','licenses') + ['licensedEmails'=>array_keys($licensed)];
    }
    public function key(int $org, string $id): string
    {
        $token=$this->query("SELECT license_token FROM organization_license_registry WHERE organization_id=? AND issuer_license_id=? AND license_type='user' AND assigned_email IS NOT NULL",[$org,$id])->fetchColumn();
        if (!is_string($token) || $token==='') throw new RuntimeException('Clé indisponible.',404);
        return $token;
    }
    public function assign(int $org, string $id, string $kind, int $recipient): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockOrganization($org);
            if ($kind==='member') {
                $person=$this->query("SELECT id,email FROM users WHERE organization_id=? AND id=? AND status='active' FOR UPDATE",[$org,$recipient])->fetch(PDO::FETCH_ASSOC);
            } elseif ($kind==='invitation') {
                $person=$this->query("SELECT id,email FROM organization_invitations WHERE organization_id=? AND id=? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE",[$org,$recipient])->fetch(PDO::FETCH_ASSOC);
            } else throw new RuntimeException('Destinataire invalide.');
            if (!$person) throw new RuntimeException('Destinataire introuvable ou invitation expirée.');
            $email=strtolower($person['email']);
            if ($this->query("SELECT id FROM organization_license_registry WHERE organization_id=? AND license_type='user' AND assigned_email=? AND status IN ('active','reserved','suspended') LIMIT 1",[$org,$email])->fetchColumn()) throw new RuntimeException('Cette personne possède déjà une licence attribuée.');
            $license=$this->query("SELECT * FROM organization_license_registry WHERE organization_id=? AND issuer_license_id=? AND license_type='user' FOR UPDATE",[$org,$id])->fetch(PDO::FETCH_ASSOC);
            if (!$license || $license['assigned_user_id']!==null || $license['assigned_email']!==null || !in_array($license['status'],['available','active'],true)) throw new RuntimeException('Cette licence n’est plus disponible en stock.');
            $this->assertUsable($org,$license);
            $claims=$this->verifier->verify($license['license_token']);
            $token=$this->issuer->reassignUser($claims,$email);
            $memberId=$kind==='member'?$recipient:null;
            $this->query("UPDATE application_devices SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE organization_id=? AND license_registry_id=? AND status='active'",[$org,$license['id']]);
            $this->query("UPDATE organization_license_registry SET assigned_user_id=?,assigned_email=?,license_token=?,token_hash=?,status=?,updated_at=UTC_TIMESTAMP() WHERE id=?",[$memberId,$email,$token,hash('sha256',$token),$memberId===null?'reserved':'active',$license['id']]);
            $this->query("UPDATE organization_licenses SET assigned_user_id=?,license_token=?,status=?,assigned_at=UTC_TIMESTAMP(),released_at=NULL WHERE organization_id=? AND issuer_license_id=?",[$memberId,$token,$memberId===null?'reserved':'assigned',$org,$id]);
            $this->pdo->commit();
        } catch(Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }
    private function assertUsable(int $org,array $license): void
    {
        if ($license['expires_at']!==null && strtotime($license['expires_at'].' UTC')<=time()) throw new RuntimeException('Licence expirée.');
        if (!$this->query("SELECT id FROM organization_license_registry WHERE organization_id=? AND issuer_license_id=? AND license_type='master' AND status='active' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())",[$org,$license['parent_master_license_id']])->fetchColumn()) throw new RuntimeException('La licence Master est inactive ou expirée.');
    }
    public function release(int $org,string $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockOrganization($org);
            $license=$this->query("SELECT * FROM organization_license_registry WHERE organization_id=? AND issuer_license_id=? AND license_type='user' FOR UPDATE",[$org,$id])->fetch(PDO::FETCH_ASSOC);
            if (!$license || !$license['assigned_email'] || !in_array($license['status'],['active','reserved'],true)) throw new RuntimeException('Cette licence ne peut pas être retirée.');
            $this->query("UPDATE application_devices SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE organization_id=? AND license_registry_id=? AND status='active'",[$org,$license['id']]);
            // Retire old device identifiers so the seat can later be used on the same hardware by its new owner.
            $this->query("UPDATE application_devices SET device_identifier=SHA2(CONCAT('released:',id,':',device_identifier),256) WHERE organization_id=? AND license_registry_id=? AND status='revoked'",[$org,$license['id']]);
            // Available is not an activation status. The old signed key is unusable online.
            $this->query("UPDATE organization_license_registry SET assigned_user_id=NULL,assigned_email=NULL,status='available',updated_at=UTC_TIMESTAMP() WHERE id=?",[$license['id']]);
            $this->query("UPDATE organization_licenses SET assigned_user_id=NULL,status='available',released_at=UTC_TIMESTAMP() WHERE organization_id=? AND issuer_license_id=?",[$org,$id]);
            $this->pdo->commit();
        } catch(Throwable $e) {if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function devices(int $org,int $member): array
    {
        if (!$this->query("SELECT id FROM users WHERE organization_id=? AND id=? AND status<>'removed'",[$org,$member])->fetchColumn()) throw new RuntimeException('Utilisateur introuvable.',404);
        return $this->query("SELECT id,device_name AS name,platform,status,last_seen_at AS lastSeenAt,revoked_at AS revokedAt FROM application_devices WHERE organization_id=? AND user_id=? ORDER BY last_seen_at DESC",[$org,$member])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function revoke(int $org,int $member,int $device): void
    {
        $q=$this->query("UPDATE application_devices SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE organization_id=? AND user_id=? AND id=? AND status='active'",[$org,$member,$device]);
        if (!$q->rowCount()) throw new RuntimeException('Appareil introuvable ou déjà révoqué.');
    }
    // Called within invitation acceptance's transaction, after the new user's insertion.
    public static function acceptReservation(PDO $pdo,int $org,int $user,string $email): void
    {
        $q=$pdo->prepare("UPDATE organization_license_registry SET assigned_user_id=?,status='active',updated_at=UTC_TIMESTAMP() WHERE organization_id=? AND assigned_email=? AND assigned_user_id IS NULL AND status='reserved' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())");
        $q->execute([$user,$org,strtolower($email)]);
        $q=$pdo->prepare("UPDATE organization_licenses l INNER JOIN organization_license_registry r ON r.organization_id=l.organization_id AND r.issuer_license_id=l.issuer_license_id SET l.assigned_user_id=?,l.status='assigned' WHERE r.organization_id=? AND r.assigned_user_id=? AND r.status='active'");
        $q->execute([$user,$org,$user]);
    }
}
