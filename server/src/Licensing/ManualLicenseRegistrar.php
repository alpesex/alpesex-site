<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Licensing;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class ManualLicenseRegistrar
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LicenseTokenVerifier $verifier
    ) {
    }

    /** @return array{id:string,type:string,role:?string,email:?string,status:string} */
    public function register(int $organizationId, int $managerId, string $token): array
    {
        $token = trim($token);
        $claims = $this->verifier->verify($token);
        $expiresAt = $this->expiration($claims['expiresAt'] ?? null);
        if ($expiresAt !== null && $expiresAt < new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new RuntimeException('Cette licence est expirée.');
        }

        $this->pdo->beginTransaction();
        try {
            $duplicate = $this->pdo->prepare(
                'SELECT organization_id FROM organization_license_registry
                 WHERE issuer_license_id=:license_id OR token_hash=:token_hash LIMIT 1 FOR UPDATE'
            );
            $duplicate->execute([
                'license_id' => $claims['id'],
                'token_hash' => hash('sha256', $token),
            ]);
            $existing = $duplicate->fetch();
            if (is_array($existing)) {
                if ((int) $existing['organization_id'] !== $organizationId) {
                    throw new RuntimeException('Cette licence appartient déjà à une autre organisation.');
                }
                throw new RuntimeException('Cette licence est déjà enregistrée.');
            }

            $assignedUserId = null;
            $email = null;
            $role = null;
            $parentMasterId = null;
            if ($claims['type'] === 'user') {
                $parentMasterId = (string) $claims['masterId'];
                $this->assertMasterBelongsToOrganization($organizationId, $parentMasterId, (string) $claims['organizationId']);
                $email = strtolower(trim((string) $claims['email']));
                $role = in_array($claims['role'] ?? 'user', ['user', 'manager', 'direction'], true)
                    ? (string) ($claims['role'] ?? 'user') : 'user';
                $user = $this->pdo->prepare(
                    "SELECT id FROM users WHERE organization_id=:organization_id AND email=:email
                     AND status='active' LIMIT 1 FOR UPDATE"
                );
                $user->execute(['organization_id' => $organizationId, 'email' => $email]);
                $assignedUserId = $user->fetchColumn();
                if ($assignedUserId === false) {
                    throw new RuntimeException('Invitez d’abord cette adresse e-mail dans votre organisation.');
                }
                $assignedUserId = (int) $assignedUserId;
                $assigned = $this->pdo->prepare(
                    "SELECT 1 FROM organization_license_registry
                     WHERE organization_id=:organization_id AND assigned_user_id=:user_id
                       AND license_type='user' AND status='active' LIMIT 1"
                );
                $assigned->execute(['organization_id' => $organizationId, 'user_id' => $assignedUserId]);
                if ($assigned->fetchColumn()) {
                    throw new RuntimeException('Ce membre possède déjà une licence active.');
                }
            } else {
                $this->assertOrganizationScope($organizationId, (string) $claims['organizationId']);
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO organization_license_registry
                 (organization_id,issuer_license_id,license_type,license_role,parent_master_license_id,
                  assigned_user_id,assigned_email,token_hash,license_token,status,source,expires_at,created_by_user_id)
                 VALUES (:organization_id,:license_id,:license_type,:license_role,:parent_master_id,
                         :assigned_user_id,:assigned_email,:token_hash,:license_token,'active','manual',
                         :expires_at,:created_by_user_id)"
            );
            $insert->execute([
                'organization_id' => $organizationId,
                'license_id' => $claims['id'],
                'license_type' => $claims['type'],
                'license_role' => $role,
                'parent_master_id' => $parentMasterId,
                'assigned_user_id' => $assignedUserId,
                'assigned_email' => $email,
                'token_hash' => hash('sha256', $token),
                'license_token' => $token,
                'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
                'created_by_user_id' => $managerId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'id' => (string) $claims['id'],
            'type' => (string) $claims['type'],
            'role' => $role,
            'email' => $email,
            'status' => 'active',
        ];
    }

    private function assertMasterBelongsToOrganization(int $organizationId, string $masterId, string $licenseOrganizationId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT license_token FROM organization_license_registry
             WHERE organization_id=:organization_id AND issuer_license_id=:master_id
               AND license_type='master' AND status='active' LIMIT 1"
        );
        $statement->execute(['organization_id' => $organizationId, 'master_id' => $masterId]);
        $masterToken = $statement->fetchColumn();
        if (!is_string($masterToken)) {
            throw new RuntimeException('Enregistrez d’abord la licence Master correspondante.');
        }
        $master = $this->verifier->verify($masterToken);
        if (($master['organizationId'] ?? null) !== $licenseOrganizationId) {
            throw new RuntimeException('La licence ne correspond pas à cette organisation.');
        }
    }

    private function assertOrganizationScope(int $organizationId, string $licenseOrganizationId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT license_token FROM organization_license_registry
             WHERE organization_id=:organization_id AND license_type='master' LIMIT 1"
        );
        $statement->execute(['organization_id' => $organizationId]);
        $existing = $statement->fetchColumn();
        if (is_string($existing)) {
            $claims = $this->verifier->verify($existing);
            if (($claims['organizationId'] ?? null) !== $licenseOrganizationId) {
                throw new RuntimeException('Cette Master appartient à une autre organisation.');
            }
        }
    }

    private function expiration(mixed $milliseconds): ?DateTimeImmutable
    {
        if ($milliseconds === null) {
            return null;
        }
        if (!is_int($milliseconds) || $milliseconds <= 0) {
            throw new RuntimeException('Date de validité de licence invalide.');
        }
        return (new DateTimeImmutable('@' . intdiv($milliseconds, 1000)))->setTimezone(new DateTimeZone('UTC'));
    }
}
