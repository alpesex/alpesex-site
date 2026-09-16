<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Commerce;

use AlpesEx\Portal\Licensing\LicenseIssuer;
use AlpesEx\Portal\Mail\TransactionalMailer;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class PaidOrderLicenseProvisioner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LicenseIssuer $issuer,
        private readonly TransactionalMailer $mailer
    ) {
    }

    /** @return array{masterToken:string,licenseNumbers:list<string>,alreadyProvisioned:bool} */
    public function provision(
        string $publicOrderId,
        string $invoiceNumber,
        string $invoiceUrl,
        DateTimeImmutable $paidAt
    ): array {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicOrderId)) {
            throw new RuntimeException('Commande invalide.');
        }
        if (trim($invoiceNumber) === '' || filter_var($invoiceUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Informations de facturation invalides.');
        }

        $this->pdo->beginTransaction();
        try {
            $orderStatement = $this->pdo->prepare(
                "SELECT o.id,o.organization_id,o.created_by_user_id,o.status,o.offer_key,o.licenses_provisioned_at,
                        org.name AS company,org.license_organization_id,u.email AS manager_email
                 FROM orders o
                 INNER JOIN organizations org ON org.id=o.organization_id
                 INNER JOIN users u ON u.id=o.created_by_user_id
                 WHERE o.public_id=:public_id LIMIT 1 FOR UPDATE"
            );
            $orderStatement->execute(['public_id' => $publicOrderId]);
            $order = $orderStatement->fetch();
            if (!is_array($order)) {
                throw new RuntimeException('Commande introuvable.');
            }

            if ($order['licenses_provisioned_at'] !== null) {
                $result = $this->existingResult((int) $order['id']);
                $this->pdo->commit();
                return $result + ['alreadyProvisioned' => true];
            }
            if (!in_array($order['status'], ['draft', 'pending_payment', 'paid'], true)) {
                throw new RuntimeException('Cette commande ne peut pas être provisionnée.');
            }

            $organizationLicenseId = (string) ($order['license_organization_id'] ?? '');
            if ($organizationLicenseId === '') {
                $organizationLicenseId = 'org-' . bin2hex(random_bytes(16));
                $updateOrganization = $this->pdo->prepare(
                    'UPDATE organizations SET license_organization_id=:license_id WHERE id=:id'
                );
                $updateOrganization->execute([
                    'license_id' => $organizationLicenseId,
                    'id' => $order['organization_id'],
                ]);
            }

            $quantities = $this->licenseQuantities((int) $order['id']);
            $expiresAt = $order['offer_key'] === 'trial'
                ? $paidAt->modify('+1 month')
                : $paidAt->modify('+10 years');
            $masterId = 'master-' . bin2hex(random_bytes(16));
            $master = $this->issuer->issueMaster(
                $masterId,
                $organizationLicenseId,
                (string) $order['company'],
                (string) $order['offer_key'],
                $expiresAt
            );

            $masterNumber = $this->newLicenseNumber('master');
            $insert = $this->pdo->prepare(
                'INSERT INTO organization_licenses
                 (organization_id,order_id,license_number,license_id,signed_token,license_type,authority_id,
                  assigned_user_id,status,assigned_at,issued_at,expires_at)
                 VALUES (:organization_id,:order_id,:license_number,:license_id,:signed_token,:license_type,
                         :authority_id,:assigned_user_id,:status,:assigned_at,:issued_at,:expires_at)'
            );
            $insert->execute([
                'organization_id' => $order['organization_id'],
                'order_id' => $order['id'],
                'license_number' => $masterNumber,
                'license_id' => $masterId,
                'signed_token' => $master['token'],
                'license_type' => 'master',
                'authority_id' => LicenseIssuer::AUTHORITY_ID,
                'assigned_user_id' => $order['created_by_user_id'],
                'status' => 'assigned',
                'assigned_at' => $paidAt->format('Y-m-d H:i:s'),
                'issued_at' => $paidAt->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);

            $licenseNumbers = [$masterNumber];
            foreach (['user', 'manager', 'direction'] as $type) {
                for ($index = 0; $index < $quantities[$type]; $index++) {
                    $number = $this->newLicenseNumber($type);
                    $insert->execute([
                        'organization_id' => $order['organization_id'],
                        'order_id' => $order['id'],
                        'license_number' => $number,
                        'license_id' => null,
                        'signed_token' => null,
                        'license_type' => $type,
                        'authority_id' => LicenseIssuer::AUTHORITY_ID,
                        'assigned_user_id' => null,
                        'status' => 'available',
                        'assigned_at' => null,
                        'issued_at' => null,
                        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                    ]);
                    $licenseNumbers[] = $number;
                }
            }

            $updateOrder = $this->pdo->prepare(
                "UPDATE orders SET status='paid',paid_at=:paid_at,invoice_number=:invoice_number,
                 invoice_url=:invoice_url,licenses_provisioned_at=UTC_TIMESTAMP() WHERE id=:id"
            );
            $updateOrder->execute([
                'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                'invoice_number' => $invoiceNumber,
                'invoice_url' => $invoiceUrl,
                'id' => $order['id'],
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->mailer->paidOrderLicenses(
            (string) $order['manager_email'],
            $invoiceNumber,
            $invoiceUrl,
            $master['token'],
            $licenseNumbers
        );

        return ['masterToken' => $master['token'], 'licenseNumbers' => $licenseNumbers, 'alreadyProvisioned' => false];
    }

    /** @return array{masterToken:string,licenseNumbers:list<string>} */
    private function existingResult(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT license_number,license_type,signed_token FROM organization_licenses WHERE order_id=:order_id ORDER BY id'
        );
        $statement->execute(['order_id' => $orderId]);
        $masterToken = '';
        $numbers = [];
        foreach ($statement->fetchAll() as $license) {
            $numbers[] = (string) $license['license_number'];
            if ($license['license_type'] === 'master') {
                $masterToken = (string) $license['signed_token'];
            }
        }
        if ($masterToken === '') {
            throw new RuntimeException('Provisionnement de licence incohérent.');
        }
        return ['masterToken' => $masterToken, 'licenseNumbers' => $numbers];
    }

    /** @return array{user:int,manager:int,direction:int} */
    private function licenseQuantities(int $orderId): array
    {
        $result = ['user' => 0, 'manager' => 0, 'direction' => 0];
        $statement = $this->pdo->prepare(
            'SELECT item_key,quantity,metadata_json FROM order_items WHERE order_id=:order_id ORDER BY id'
        );
        $statement->execute(['order_id' => $orderId]);
        foreach ($statement->fetchAll() as $item) {
            $metadata = json_decode((string) ($item['metadata_json'] ?? '{}'), true);
            if (str_starts_with((string) $item['item_key'], 'offer_')) {
                foreach ($result as $type => $_) {
                    $result[$type] += max(0, (int) ($metadata['included'][$type] ?? 0));
                }
                continue;
            }
            $key = (string) $item['item_key'];
            $type = str_starts_with($key, 'user') ? 'user' : $key;
            if (isset($result[$type])) {
                $result[$type] += (int) $item['quantity'] * max(0, (int) ($metadata['licenses_per_unit'] ?? 0));
            }
        }
        return $result;
    }

    private function newLicenseNumber(string $type): string
    {
        $prefix = match ($type) {
            'master' => 'MST',
            'manager' => 'MGR',
            'direction' => 'DIR',
            default => 'USR',
        };
        do {
            $number = 'ALX-' . $prefix . '-' . strtoupper(bin2hex(random_bytes(8)));
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM organization_licenses WHERE license_number=:license_number LIMIT 1'
            );
            $statement->execute(['license_number' => $number]);
        } while ($statement->fetchColumn());
        return $number;
    }
}
