<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Team;

use AlpesEx\Portal\Licensing\LicenseIssuer;
use AlpesEx\Portal\Mail\TransactionalMailer;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class MemberManager
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionalMailer $mailer,
        private readonly LicenseIssuer $issuer
    ) {
    }

    /** @return array{licenseNumber:string,signedToken:string,reused:bool} */
    public function assignUserLicense(int $organizationId, int $memberId, string $licenseType = 'user'): array
    {
        if (!in_array($licenseType, ['user', 'manager', 'direction'], true)) {
            throw new RuntimeException('Type de licence invalide.');
        }
        $this->pdo->beginTransaction();
        try {
            $member = $this->memberForUpdate($organizationId, $memberId);
            if ($member['status'] !== 'active') {
                throw new RuntimeException('Ce membre ne possède pas de compte actif.');
            }

            $existing = $this->pdo->prepare(
                "SELECT license_number FROM organization_licenses
                 WHERE organization_id = :organization_id AND assigned_user_id = :user_id
                   AND license_type <> 'master' AND status = 'assigned' LIMIT 1"
            );
            $existing->execute(['organization_id' => $organizationId, 'user_id' => $memberId]);
            if ($existing->fetch()) {
                throw new RuntimeException('Une licence est déjà affectée à ce membre.');
            }

            $available = $this->pdo->prepare(
                "SELECT id, license_number, expires_at FROM organization_licenses
                 WHERE organization_id = :organization_id AND license_type = :license_type
                   AND assigned_user_id IS NULL AND status = 'available'
                 ORDER BY released_at IS NULL ASC, released_at ASC, id ASC
                 LIMIT 1 FOR UPDATE"
            );
            $available->execute(['organization_id' => $organizationId, 'license_type' => $licenseType]);
            $license = $available->fetch();
            $reused = is_array($license);

            if (!$reused) {
                throw new RuntimeException('Aucune licence de ce type n’est disponible dans le stock.');
            }

            $organization = $this->licenseContext($organizationId);
            $licenseNumber = (string) $license['license_number'];
            $licenseId = 'lic-' . $licenseType . '-' . bin2hex(random_bytes(16));
            $expiresAt = new DateTimeImmutable((string) $license['expires_at'], new DateTimeZone('UTC'));
            $issued = $this->issuer->issueUser(
                $licenseId,
                (string) $organization['license_organization_id'],
                (string) $organization['master_license_id'],
                (string) $organization['company'],
                (string) $member['email'],
                $licenseType,
                $expiresAt
            );
            $assign = $this->pdo->prepare(
                "UPDATE organization_licenses
                 SET license_id=:license_id,signed_token=:signed_token,assigned_user_id=:user_id,
                     status='assigned',assigned_at=UTC_TIMESTAMP(),issued_at=UTC_TIMESTAMP(),
                     released_at=NULL,revoked_at=NULL
                 WHERE id=:id"
            );
            $assign->execute([
                'license_id' => $licenseId,
                'signed_token' => $issued['token'],
                'user_id' => $memberId,
                'id' => $license['id'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->mailer->userLicenseAssigned(
            (string) $member['email'],
            (string) $member['first_name'],
            $licenseType,
            $licenseNumber,
            $issued['token']
        );

        return ['licenseNumber' => $licenseNumber, 'signedToken' => $issued['token'], 'reused' => $reused];
    }

    public function removeMember(int $organizationId, int $managerId, int $memberId): void
    {
        if ($memberId === $managerId) {
            throw new RuntimeException('Vous ne pouvez pas supprimer votre propre compte.');
        }

        $this->pdo->beginTransaction();
        try {
            $member = $this->memberForUpdate($organizationId, $memberId);
            if ($member['role'] === 'manager') {
                throw new RuntimeException('Un autre gestionnaire ne peut pas être supprimé depuis cette interface.');
            }

            $revoke = $this->pdo->prepare(
                "INSERT IGNORE INTO license_revocations (organization_id,license_id,reason)
                 SELECT organization_id,license_id,'member_removed'
                 FROM organization_licenses
                 WHERE organization_id=:organization_id AND assigned_user_id=:user_id
                   AND license_type<>'master' AND license_id IS NOT NULL"
            );
            $revoke->execute(['organization_id' => $organizationId, 'user_id' => $memberId]);

            $release = $this->pdo->prepare(
                "UPDATE organization_licenses
                 SET assigned_user_id = NULL, status = 'available',
                     license_id=NULL,signed_token=NULL,issued_at=NULL,
                     released_at = UTC_TIMESTAMP(),revoked_at=UTC_TIMESTAMP()
                 WHERE organization_id = :organization_id AND assigned_user_id = :user_id
                   AND license_type <> 'master'"
            );
            $release->execute(['organization_id' => $organizationId, 'user_id' => $memberId]);

            $remove = $this->pdo->prepare(
                "UPDATE users SET status = 'removed' WHERE id = :id AND organization_id = :organization_id"
            );
            $remove->execute(['id' => $memberId, 'organization_id' => $organizationId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function memberForUpdate(int $organizationId, int $memberId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, first_name, role, status
             FROM users
             WHERE id = :id AND organization_id = :organization_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $memberId, 'organization_id' => $organizationId]);
        $member = $statement->fetch();
        if (!is_array($member) || $member['status'] === 'removed') {
            throw new RuntimeException('Membre introuvable.');
        }
        return $member;
    }

    /** @return array{company:string,license_organization_id:string,master_license_id:string} */
    private function licenseContext(int $organizationId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT o.name AS company,o.license_organization_id,l.license_id AS master_license_id
             FROM organizations o
             INNER JOIN organization_licenses l ON l.organization_id=o.id
               AND l.license_type='master' AND l.status='assigned'
             WHERE o.id=:organization_id
             ORDER BY l.id DESC LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['organization_id' => $organizationId]);
        $context = $statement->fetch();
        if (!is_array($context) || !$context['license_organization_id'] || !$context['master_license_id']) {
            throw new RuntimeException('La licence Master de l’organisation est absente ou inactive.');
        }
        return $context;
    }
}
