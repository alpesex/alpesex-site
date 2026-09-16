<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Team;

use AlpesEx\Portal\Mail\TransactionalMailer;
use PDO;
use RuntimeException;
use Throwable;

final class MemberManager
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TransactionalMailer $mailer
    ) {
    }

    /** @return array{licenseNumber:string,reused:bool} */
    public function assignUserLicense(int $organizationId, int $memberId): array
    {
        $this->pdo->beginTransaction();
        try {
            $member = $this->memberForUpdate($organizationId, $memberId);
            if ($member['role'] === 'manager') {
                throw new RuntimeException('La licence du gestionnaire ne peut pas être modifiée ici.');
            }
            if ($member['status'] !== 'active') {
                throw new RuntimeException('Ce membre ne possède pas de compte actif.');
            }

            $existing = $this->pdo->prepare(
                "SELECT license_number FROM organization_licenses
                 WHERE organization_id = :organization_id AND assigned_user_id = :user_id
                   AND status = 'assigned' LIMIT 1"
            );
            $existing->execute(['organization_id' => $organizationId, 'user_id' => $memberId]);
            if ($existing->fetch()) {
                throw new RuntimeException('Une licence est déjà affectée à ce membre.');
            }

            $available = $this->pdo->prepare(
                "SELECT id, license_number, license_token FROM organization_licenses
                 WHERE organization_id = :organization_id AND license_type = 'user'
                   AND assigned_user_id IS NULL AND status = 'available'
                   AND license_token IS NOT NULL
                 ORDER BY released_at IS NULL ASC, released_at ASC, id ASC
                 LIMIT 1 FOR UPDATE"
            );
            $available->execute(['organization_id' => $organizationId]);
            $license = $available->fetch();
            if (!is_array($license)) {
                throw new RuntimeException('Aucune licence Utilisateur signée n’est disponible dans votre stock.');
            }

            $assign = $this->pdo->prepare(
                "UPDATE organization_licenses
                 SET assigned_user_id = :user_id, status = 'assigned',
                     assigned_at = UTC_TIMESTAMP(), released_at = NULL
                 WHERE id = :id"
            );
            $assign->execute(['user_id' => $memberId, 'id' => $license['id']]);
            $register = $this->pdo->prepare(
                "UPDATE organization_license_registry
                 SET assigned_user_id=:user_id,assigned_email=:email,status='active'
                 WHERE token_hash=:token_hash"
            );
            $register->execute([
                'user_id' => $memberId,
                'email' => strtolower((string) $member['email']),
                'token_hash' => hash('sha256', (string) $license['license_token']),
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
            (string) $license['license_token']
        );

        return ['licenseNumber' => (string) $license['license_number'], 'reused' => true];
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

            $revokeRegistry = $this->pdo->prepare(
                "UPDATE organization_license_registry
                 SET status='revoked'
                 WHERE organization_id=:organization_id AND assigned_user_id=:user_id
                   AND license_type='user'"
            );
            $revokeRegistry->execute(['organization_id' => $organizationId, 'user_id' => $memberId]);

            $release = $this->pdo->prepare(
                "UPDATE organization_licenses
                 SET assigned_user_id = NULL, status = 'revoked',
                     released_at = UTC_TIMESTAMP()
                 WHERE organization_id = :organization_id AND assigned_user_id = :user_id"
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

}
