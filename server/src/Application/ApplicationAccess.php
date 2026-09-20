<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Application;

use PDO;
use RuntimeException;

final class ApplicationAccess
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int,organizationId:int,email:string,firstName:string,lastName:string,role:string} */
    public function sessionUser(): array
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $organizationId = filter_var($_SESSION['organization_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || !$organizationId) {
            throw new RuntimeException('AUTHENTICATION_REQUIRED', 401);
        }
        $query = $this->pdo->prepare(
            "SELECT id,organization_id,email,first_name,last_name,role,status
             FROM users WHERE id=:id AND organization_id=:organization_id LIMIT 1"
        );
        $query->execute(['id' => $userId, 'organization_id' => $organizationId]);
        $row = $query->fetch();
        if (!is_array($row) || $row['status'] !== 'active') {
            throw new RuntimeException('AUTHENTICATION_REQUIRED', 401);
        }
        return [
            'id' => (int) $row['id'],
            'organizationId' => (int) $row['organization_id'],
            'email' => (string) $row['email'],
            'firstName' => (string) $row['first_name'],
            'lastName' => (string) $row['last_name'],
            'role' => (string) $row['role'],
        ];
    }

    /** @param array{id:int,organizationId:int,email:string,role:string} $user */
    public function activateDevice(array $user, string $licenseToken, string $deviceIdentifier, string $deviceName, string $platform = 'web', string $previousDeviceIdentifier = ''): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $deviceIdentifier)) {
            throw new RuntimeException('DEVICE_INVALID', 422);
        }
        if (!in_array($platform, ['web', 'ios', 'android', 'windows'], true)) {
            throw new RuntimeException('DEVICE_INVALID', 422);
        }
        $this->pdo->beginTransaction();
        try {
            $license = $this->pdo->prepare(
                "SELECT id,license_role,status,expires_at,assigned_user_id,assigned_email
                 FROM organization_license_registry
                 WHERE organization_id=:organization_id AND token_hash=:token_hash AND license_type='user' LIMIT 1 FOR UPDATE"
            );
            $license->execute([
                'organization_id' => $user['organizationId'],
                'token_hash' => hash('sha256', $licenseToken),
            ]);
            $row = $license->fetch();
            $expired = is_array($row) && $row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') <= time();
            if (!is_array($row) || $row['status'] !== 'active' || $expired) {
                throw new RuntimeException('LICENSE_INVALID', 403);
            }
            if ((int) ($row['assigned_user_id'] ?? 0) !== $user['id']
                || strtolower((string) ($row['assigned_email'] ?? '')) !== strtolower($user['email'])) {
                throw new RuntimeException('LICENSE_ACCOUNT_MISMATCH', 403);
            }
            $existing = $this->pdo->prepare(
                'SELECT id,status FROM application_devices WHERE license_registry_id=:license_id AND device_identifier=:device_identifier LIMIT 1'
            );
            $existing->execute(['license_id' => $row['id'], 'device_identifier' => $deviceIdentifier]);
            $device = $existing->fetch();
            if (!is_array($device) && $previousDeviceIdentifier !== $deviceIdentifier
                && preg_match('/^[a-f0-9]{64}$/', $previousDeviceIdentifier)) {
                $legacy = $this->pdo->prepare(
                    'SELECT id,status FROM application_devices WHERE license_registry_id=:license_id AND device_identifier=:device_identifier LIMIT 1 FOR UPDATE'
                );
                $legacy->execute(['license_id' => $row['id'], 'device_identifier' => $previousDeviceIdentifier]);
                $device = $legacy->fetch();
                if (is_array($device) && $device['status'] === 'active') {
                    $migrate = $this->pdo->prepare(
                        'UPDATE application_devices SET device_identifier=:device_identifier,device_name=:device_name,platform=:platform,last_seen_at=UTC_TIMESTAMP() WHERE id=:id'
                    );
                    $migrate->execute(['device_identifier' => $deviceIdentifier, 'device_name' => mb_substr(trim($deviceName) ?: 'PC Windows', 0, 190), 'platform' => $platform, 'id' => $device['id']]);
                }
            }
            if (is_array($device) && $device['status'] === 'revoked') {
                throw new RuntimeException('DEVICE_REVOKED', 403);
            }
            if (!is_array($device)) {
                $count = $this->pdo->prepare("SELECT COUNT(*) FROM application_devices WHERE license_registry_id=:license_id AND status='active'");
                $count->execute(['license_id' => $row['id']]);
                if ((int) $count->fetchColumn() >= 3) {
                    throw new RuntimeException('DEVICE_LIMIT_REACHED', 403);
                }
                $insert = $this->pdo->prepare(
                    "INSERT INTO application_devices
                     (organization_id,user_id,license_registry_id,device_identifier,device_name,platform)
                     VALUES (:organization_id,:user_id,:license_id,:device_identifier,:device_name,:platform)"
                );
                $insert->execute([
                    'organization_id' => $user['organizationId'], 'user_id' => $user['id'],
                    'license_id' => $row['id'], 'device_identifier' => $deviceIdentifier, 'platform' => $platform,
                    'device_name' => mb_substr(trim($deviceName) ?: 'Mobile / tablette', 0, 190),
                ]);
            } else {
                $touch = $this->pdo->prepare('UPDATE application_devices SET last_seen_at=UTC_TIMESTAMP(),device_name=:device_name,platform=:platform WHERE id=:id');
                $touch->execute(['device_name' => mb_substr(trim($deviceName) ?: 'Mobile / tablette', 0, 190), 'platform' => $platform, 'id' => $device['id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        $_SESSION['application_license_id'] = (int) $row['id'];
        $_SESSION['application_device'] = $deviceIdentifier;
        return ['role' => (string) ($row['license_role'] ?: $user['role']), 'maxDevices' => 3];
    }

    /** @param array{id:int,organizationId:int} $user */
    public function assertActiveDevice(array $user): string
    {
        $licenseId = filter_var($_SESSION['application_license_id'] ?? null, FILTER_VALIDATE_INT);
        $deviceIdentifier = strtolower((string) ($_SESSION['application_device'] ?? ''));
        if (!$licenseId || !preg_match('/^[a-f0-9]{64}$/', $deviceIdentifier)) {
            throw new RuntimeException('LICENSE_ACTIVATION_REQUIRED', 401);
        }
        $query = $this->pdo->prepare(
            "SELECT d.id,r.status,r.expires_at,r.license_role,r.assigned_user_id,r.assigned_email,o.status AS organization_status,
                    parent.status AS parent_status,parent.expires_at AS parent_expires_at
             FROM application_devices d
             INNER JOIN organization_license_registry r ON r.id=d.license_registry_id
             INNER JOIN organizations o ON o.id=d.organization_id
             LEFT JOIN organization_license_registry parent
               ON parent.organization_id=r.organization_id
              AND parent.issuer_license_id=r.parent_master_license_id
              AND parent.license_type='master'
             WHERE d.license_registry_id=:license_id AND d.device_identifier=:device_identifier
               AND d.user_id=:user_id AND d.organization_id=:organization_id AND d.status='active'
             LIMIT 1"
        );
        $query->execute([
            'license_id' => $licenseId, 'device_identifier' => $deviceIdentifier,
            'user_id' => $user['id'], 'organization_id' => $user['organizationId'],
        ]);
        $row = $query->fetch();
        $expired = is_array($row) && $row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') <= time();
        $parentExpired = is_array($row) && $row['parent_expires_at'] !== null && strtotime((string) $row['parent_expires_at'] . ' UTC') <= time();
        if (!is_array($row) || $row['status'] !== 'active' || $row['organization_status'] !== 'active'
            || $row['parent_status'] !== 'active' || $expired || $parentExpired
            || (int) $row['assigned_user_id'] !== $user['id']
            || strtolower((string) $row['assigned_email']) !== strtolower($user['email'])) {
            unset($_SESSION['application_license_id'], $_SESSION['application_device']);
            throw new RuntimeException('LICENSE_INVALID', 403);
        }
        $role = (string) ($row['license_role'] ?? '');
        if (!in_array($role, ['user', 'manager', 'direction'], true)) {
            throw new RuntimeException('LICENSE_INVALID', 403);
        }
        $touch = $this->pdo->prepare('UPDATE application_devices SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id');
        $touch->execute(['id' => $row['id']]);
        return $user['role'] === 'direction' ? 'direction' : $role;
    }

    /** @param array{id:int,organizationId:int,role:string} $user */
    public function projectAccess(array $user, array $project): string
    {
        if ((int) $project['organization_id'] !== $user['organizationId']) {
            return 'none';
        }
        if ((int) $project['owner_user_id'] === $user['id']) {
            return $user['role'] === 'direction' ? 'viewer' : 'editor';
        }
        if ($user['role'] === 'direction') {
            return 'viewer';
        }
        if ($user['role'] === 'manager') {
            return (int) ($project['team_manager_id'] ?? 0) === $user['id'] ? 'editor' : 'none';
        }
        return 'none';
    }
}
