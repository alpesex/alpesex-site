<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Security;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class SecureDownloadLink
{
    private const RESOURCE_TYPES = ['invoice', 'license', 'download'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function issue(
        int $organizationId,
        ?int $userId,
        string $resourceType,
        string $resourceReference,
        int $validForSeconds = 3600,
        int $maxDownloads = 3
    ): string {
        if (!in_array($resourceType, self::RESOURCE_TYPES, true)) {
            throw new RuntimeException('Type de ressource privée invalide.');
        }
        if (preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $resourceReference) !== 1) {
            throw new RuntimeException('Référence de ressource privée invalide.');
        }
        if ($validForSeconds < 60 || $validForSeconds > 2592000) {
            throw new RuntimeException('Durée du lien privé invalide.');
        }
        if ($maxDownloads < 1 || $maxDownloads > 20) {
            throw new RuntimeException('Nombre de téléchargements invalide.');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $validForSeconds . ' seconds')
            ->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO secure_download_links
             (organization_id, issued_to_user_id, resource_type, resource_reference,
              token_hash, max_downloads, expires_at)
             VALUES
             (:organization_id, :user_id, :resource_type, :resource_reference,
              :token_hash, :max_downloads, :expires_at)'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'resource_type' => $resourceType,
            'resource_reference' => $resourceReference,
            'token_hash' => hash('sha256', $token),
            'max_downloads' => $maxDownloads,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /** @return array{organizationId:int,userId:?int,resourceType:string,resourceReference:string} */
    public function consume(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new RuntimeException('Lien de téléchargement invalide ou expiré.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, organization_id, issued_to_user_id, resource_type,
                        resource_reference, download_count, max_downloads
                 FROM secure_download_links
                 WHERE token_hash = :token_hash
                   AND revoked_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()
                   AND download_count < max_downloads
                 LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $link = $statement->fetch();
            if (!is_array($link)) {
                throw new RuntimeException('Lien de téléchargement invalide ou expiré.');
            }

            $consume = $this->pdo->prepare(
                'UPDATE secure_download_links
                 SET download_count = download_count + 1,
                     last_downloaded_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $consume->execute(['id' => $link['id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'organizationId' => (int) $link['organization_id'],
            'userId' => $link['issued_to_user_id'] !== null
                ? (int) $link['issued_to_user_id']
                : null,
            'resourceType' => (string) $link['resource_type'],
            'resourceReference' => (string) $link['resource_reference'],
        ];
    }

    public function revokeResource(
        int $organizationId,
        string $resourceType,
        string $resourceReference
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE secure_download_links
             SET revoked_at = UTC_TIMESTAMP()
             WHERE organization_id = :organization_id
               AND resource_type = :resource_type
               AND resource_reference = :resource_reference
               AND revoked_at IS NULL'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'resource_type' => $resourceType,
            'resource_reference' => $resourceReference,
        ]);
    }
}
