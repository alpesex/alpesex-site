<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Auth;

use PDO;
use RuntimeException;

final class Login
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $input
     *  @return array{id:int, organizationId:?int, email:string, firstName:string, lastName:string, role:string}
     */
    public function execute(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $profileType = (string) ($input['profileType'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $password === '') {
            throw new RuntimeException('Adresse e-mail ou mot de passe incorrect.');
        }
        if (!in_array($profileType, ['manager', 'user'], true)) {
            throw new RuntimeException('Type de profil invalide.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id, organization_id, email, password_hash, first_name, last_name, role, status, email_verified_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        $valid = is_array($user)
            && password_verify($password, (string) $user['password_hash'])
            && $user['status'] === 'active'
            && $user['email_verified_at'] !== null;
        if ($valid) {
            $profileMatches = ($profileType === 'manager' && $user['role'] === 'manager')
                || ($profileType === 'user' && $user['role'] !== 'manager');
            if (!$profileMatches && $user['organization_id'] !== null) {
                // Application clients choose their login profile from the licence role.
                // Return the actual account role below; a Manager licence does not grant portal administration.
                $license = $this->pdo->prepare(
                    "SELECT r.license_role FROM organization_license_registry r
                     INNER JOIN organization_license_registry p ON p.organization_id=r.organization_id
                       AND p.issuer_license_id=r.parent_master_license_id AND p.license_type='master'
                     WHERE r.organization_id=? AND r.assigned_user_id=? AND r.license_type='user'
                       AND r.status='active' AND p.status='active'
                       AND (r.expires_at IS NULL OR r.expires_at>UTC_TIMESTAMP())
                       AND (p.expires_at IS NULL OR p.expires_at>UTC_TIMESTAMP())"
                );
                $license->execute([$user['organization_id'], $user['id']]);
                foreach ($license->fetchAll(PDO::FETCH_COLUMN) as $role) {
                    if (($role === 'manager' ? 'manager' : 'user') === $profileType) $profileMatches = true;
                }
            }
            $valid = $profileMatches;
        }

        if (!$valid) {
            throw new RuntimeException('Adresse e-mail, mot de passe ou type de profil incorrect.');
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $rehash->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $user['id'],
            ]);
        }

        return [
            'id' => (int) $user['id'],
            'organizationId' => $user['organization_id'] !== null ? (int) $user['organization_id'] : null,
            'email' => (string) $user['email'],
            'firstName' => (string) $user['first_name'],
            'lastName' => (string) $user['last_name'],
            'role' => (string) $user['role'],
        ];
    }
}
