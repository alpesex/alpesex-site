<?php

declare(strict_types=1);

use AlpesEx\Portal\Application\ApplicationAccess;
use AlpesEx\Portal\Application\ApplicationCipher;
use AlpesEx\Portal\Application\DeviceAlreadyConnectedException;
use AlpesEx\Portal\Database;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function appFail(int $status, string $error, array $details = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error] + $details, JSON_UNESCAPED_UNICODE);
    exit;
}

function appJson(): array
{
    $decoded = json_decode(file_get_contents('php://input') ?: '{}', true, 256, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        appFail(422, 'INVALID_REQUEST');
    }
    return $decoded;
}

function appUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function appProject(PDO $pdo, int $organizationId, string $id, bool $lock = false): array
{
    $query = $pdo->prepare(
        'SELECT p.*,u.email AS owner_email,u.role AS owner_role,u.team_manager_id
         FROM application_projects p INNER JOIN users u ON u.id=p.owner_user_id
         WHERE p.id=:id AND p.organization_id=:organization_id AND p.deleted_at IS NULL LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $query->execute(['id' => $id, 'organization_id' => $organizationId]);
    $row = $query->fetch();
    if (!is_array($row)) {
        appFail(404, 'PROJECT_NOT_FOUND');
    }
    return $row;
}

function appProjectIdentity(array $data, array $row): string
{
    $reference = mb_strtoupper(trim((string) ($data['meta']['referenceProjet'] ?? '')));
    $reference = preg_replace('/\s+/u', ' ', $reference) ?: '';
    if ($reference === '') {
        return '';
    }
    return (string) $row['owner_user_id'] . '|' . $reference;
}

try {
    session_name('ALPESEXSESSID');
    session_set_cookie_params(['path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();

    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 3) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);
    $access = new ApplicationAccess($pdo);
    $cipher = new ApplicationCipher((string) ($_ENV['ALPESEX_APPLICATION_KEY'] ?? ''));
    $user = $access->sessionUser();
    $action = trim((string) ($_GET['action'] ?? 'session'));
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($action === 'activate' && $method === 'POST') {
        $input = appJson();
        $activation = $access->activateDevice(
            $user,
            trim((string) ($input['licenseToken'] ?? '')),
            strtolower(trim((string) ($input['deviceIdentifier'] ?? ''))),
            trim((string) ($input['deviceName'] ?? '')),
            trim((string) ($input['platform'] ?? 'web')),
            strtolower(trim((string) ($input['previousDeviceIdentifier'] ?? '')))
        );
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['user' => $user, 'activation' => $activation], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($action, ['devices', 'device-revoke', 'device-disconnect'], true)) {
        if (!isset($_SESSION['application_license_id'], $_SESSION['application_device'])) {
            appFail(401, 'LICENSE_ACTIVATION_REQUIRED');
        }
        $user['role'] = $access->assertActiveDevice($user);
    }

    if ($action === 'device-disconnect' && $method === 'POST') {
        $access->disconnectDevice($user);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'verify-password' && $method === 'POST') {
        (new \AlpesEx\Portal\Security\RateLimiter($pdo))->assertAllowed('application-password', (string) $user['id'], 10, 3600);
        $input = appJson();
        $query = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
        $query->execute(['id' => $user['id']]);
        if (!password_verify((string) ($input['password'] ?? ''), (string) $query->fetchColumn())) {
            appFail(403, 'PASSWORD_INVALID');
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'session' && $method === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['user' => $user, 'active' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'devices' && $method === 'GET') {
        $query = $pdo->prepare(
            'SELECT d.id,d.device_identifier,d.device_name,d.platform,d.status,d.first_seen_at,d.last_seen_at,r.issuer_license_id
             FROM application_devices d
             INNER JOIN organization_license_registry r ON r.id=d.license_registry_id
             WHERE d.organization_id=:organization_id AND (:is_manager=1 OR d.user_id=:user_id)
             ORDER BY d.last_seen_at DESC'
        );
        $query->execute(['organization_id' => $user['organizationId'], 'is_manager' => $user['role'] === 'manager' ? 1 : 0, 'user_id' => $user['id']]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['devices' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'name' => $row['device_name'], 'platform' => $row['platform'],
            'status' => $row['status'], 'licenseId' => $row['issuer_license_id'],
            'firstSeenAt' => $row['first_seen_at'], 'lastSeenAt' => $row['last_seen_at'],
            'current' => hash_equals((string) ($_SESSION['application_device'] ?? ''), (string) $row['device_identifier']),
        ], $query->fetchAll())], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'device-revoke' && $method === 'POST') {
        $input = appJson();
        $deviceId = filter_var($input['deviceId'] ?? null, FILTER_VALIDATE_INT);
        if (!$deviceId) {
            appFail(422, 'DEVICE_INVALID');
        }
        $sql = "UPDATE application_devices SET status='revoked',revoked_at=UTC_TIMESTAMP()
                WHERE id=:id AND organization_id=:organization_id";
        $parameters = ['id' => $deviceId, 'organization_id' => $user['organizationId']];
        if ($user['role'] !== 'manager') {
            $sql .= ' AND user_id=:user_id';
            $parameters['user_id'] = $user['id'];
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        if ($statement->rowCount() !== 1) {
            appFail(404, 'DEVICE_NOT_FOUND');
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'projects' && $method === 'GET') {
        $query = $pdo->prepare(
            'SELECT p.*,u.email AS owner_email,u.role AS owner_role,u.team_manager_id,
                    EXISTS(SELECT 1 FROM application_documents d WHERE d.project_id=p.id) AS has_documents
             FROM application_projects p INNER JOIN users u ON u.id=p.owner_user_id
             WHERE p.organization_id=:organization_id AND p.deleted_at IS NULL ORDER BY p.updated_at DESC'
        );
        $query->execute(['organization_id' => $user['organizationId']]);
        $projects = [];
        foreach ($query->fetchAll() as $row) {
            $permission = $access->projectAccess($user, $row);
            if ($permission === 'none') {
                continue;
            }
            $data = json_decode($cipher->decrypt((string) $row['project_data'], 'project:' . $row['id']), true);
            if (!is_array($data)) {
                continue;
            }
            $projects[] = [
                'id' => $row['id'], 'localId' => $row['local_id'], 'name' => $row['name'],
                'ownerEmail' => $row['owner_email'], 'revision' => (int) $row['revision'],
                'updatedAt' => $row['updated_at'], 'access' => $permission,
                'hasDocuments' => (bool) $row['has_documents'], 'data' => $data,
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['projects' => $projects], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'project-prune' && $method === 'POST') {
        if ($user['role'] === 'direction') {
            appFail(403, 'READ_ONLY');
        }
        $input = appJson();
        $canonicalId = strtolower(trim((string) ($input['canonicalId'] ?? '')));
        $duplicateIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): string => strtolower(trim((string) $id)), (array) ($input['duplicateIds'] ?? [])),
            static fn (string $id): bool => $id !== '' && $id !== $canonicalId
        )));
        if ($canonicalId === '' || count($duplicateIds) > 20) {
            appFail(422, 'PROJECT_INVALID');
        }
        sort($duplicateIds);
        $pdo->beginTransaction();
        $canonical = appProject($pdo, $user['organizationId'], $canonicalId, true);
        if ($access->projectAccess($user, $canonical) !== 'editor') {
            appFail(403, 'READ_ONLY');
        }
        $canonicalData = json_decode($cipher->decrypt((string) $canonical['project_data'], 'project:' . $canonical['id']), true);
        $identity = is_array($canonicalData) ? appProjectIdentity($canonicalData, $canonical) : '';
        if ($identity === '') {
            appFail(422, 'PROJECT_INVALID');
        }
        $deletedIds = [];
        foreach ($duplicateIds as $duplicateId) {
            $duplicate = appProject($pdo, $user['organizationId'], $duplicateId, true);
            if ($access->projectAccess($user, $duplicate) !== 'editor') {
                appFail(403, 'READ_ONLY');
            }
            $duplicateData = json_decode($cipher->decrypt((string) $duplicate['project_data'], 'project:' . $duplicate['id']), true);
            if (!is_array($duplicateData) || !hash_equals($identity, appProjectIdentity($duplicateData, $duplicate))) {
                appFail(409, 'PROJECT_CONFLICT');
            }
            $documents = $pdo->prepare('SELECT COUNT(*) FROM application_documents WHERE project_id=:project_id');
            $documents->execute(['project_id' => $duplicate['id']]);
            if ((int) $documents->fetchColumn() !== 0) {
                continue;
            }
            $delete = $pdo->prepare(
                'UPDATE application_projects SET deleted_at=UTC_TIMESTAMP(),revision=revision+1,project_data=:empty
                 WHERE id=:id AND deleted_at IS NULL'
            );
            $delete->execute([
                'id' => $duplicate['id'],
                'empty' => $cipher->encrypt('{}', 'project:' . $duplicate['id']),
            ]);
            if ($delete->rowCount() === 1) {
                $deletedIds[] = (string) $duplicate['id'];
            }
        }
        $pdo->commit();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'deletedIds' => $deletedIds], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'project-save' && $method === 'POST') {
        if ($user['role'] === 'direction') {
            appFail(403, 'READ_ONLY');
        }
        $input = appJson();
        $project = $input['project'] ?? null;
        $localId = trim((string) ($project['meta']['portfolioId'] ?? ''));
        $cloudId = strtolower(trim((string) ($project['meta']['cloudId'] ?? '')));
        $name = trim((string) ($project['meta']['nomProjet'] ?? 'Projet CPMP - ASM'));
        if (!is_array($project) || $localId === '' || strlen($localId) > 120 || strlen($name) > 190) {
            appFail(422, 'PROJECT_INVALID');
        }
        $hasCloudId = preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $cloudId) === 1;
        $existing = $pdo->prepare(
            'SELECT p.*,u.team_manager_id FROM application_projects p INNER JOIN users u ON u.id=p.owner_user_id
             WHERE p.organization_id=:organization_id AND ' . ($hasCloudId ? 'p.id=:project_id' : 'p.local_id=:local_id') . ' LIMIT 1'
        );
        $existing->execute($hasCloudId
            ? ['organization_id' => $user['organizationId'], 'project_id' => $cloudId]
            : ['organization_id' => $user['organizationId'], 'local_id' => $localId]);
        $row = $existing->fetch();
        if (is_array($row) && $row['deleted_at'] !== null) {
            appFail(410, 'PROJECT_DELETED');
        }
        $plain = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!is_array($row)) {
            if ((int) ($input['revision'] ?? 0) !== 0) {
                appFail(409, 'PROJECT_CONFLICT');
            }
            $id = appUuid();
            $encoded = $cipher->encrypt($plain, 'project:' . $id);
            $insert = $pdo->prepare(
                'INSERT INTO application_projects (id,organization_id,owner_user_id,local_id,name,project_data)
                 VALUES (:id,:organization_id,:owner_user_id,:local_id,:name,:project_data)'
            );
            $insert->execute(['id' => $id, 'organization_id' => $user['organizationId'], 'owner_user_id' => $user['id'], 'local_id' => $localId, 'name' => $name, 'project_data' => $encoded]);
            $revision = 1;
        } else {
            if ($access->projectAccess($user, $row) !== 'editor') {
                appFail(403, 'READ_ONLY');
            }
            $expected = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT);
            if ($expected === false || $expected === null || $expected < 1) {
                appFail(409, 'PROJECT_CONFLICT');
            }
            if ($expected !== (int) $row['revision']) {
                appFail(409, 'PROJECT_CONFLICT');
            }
            $id = (string) $row['id'];
            $encoded = $cipher->encrypt($plain, 'project:' . $id);
            $update = $pdo->prepare(
                'UPDATE application_projects SET local_id=:local_id,name=:name,project_data=:project_data,revision=revision+1
                 WHERE id=:id AND revision=:revision AND deleted_at IS NULL'
            );
            $update->execute(['local_id' => $localId, 'name' => $name, 'project_data' => $encoded, 'id' => $id, 'revision' => $expected]);
            if ($update->rowCount() !== 1) {
                appFail(409, 'PROJECT_CONFLICT');
            }
            $revision = $expected + 1;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['id' => $id, 'localId' => $localId, 'revision' => $revision, 'access' => 'editor'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'project-delete' && $method === 'POST') {
        $input = appJson();
        $pdo->beginTransaction();
        $project = appProject($pdo, $user['organizationId'], trim((string) ($input['projectId'] ?? '')), true);
        if ($access->projectAccess($user, $project) !== 'editor') appFail(403, 'READ_ONLY');
        $expected = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($expected === false || $expected === null || $expected !== (int) $project['revision']) appFail(409, 'PROJECT_CONFLICT');
        $documents = $pdo->prepare('SELECT storage_name FROM application_documents WHERE project_id=:id');
        $documents->execute(['id' => $project['id']]);
        $files = $documents->fetchAll(PDO::FETCH_COLUMN);
        $delete = $pdo->prepare('UPDATE application_projects SET deleted_at=UTC_TIMESTAMP(),revision=revision+1,project_data=:empty WHERE id=:id AND revision=:revision AND deleted_at IS NULL');
        $delete->execute(['id' => $project['id'], 'revision' => $expected, 'empty' => $cipher->encrypt('{}', 'project:' . $project['id'])]);
        if ($delete->rowCount() !== 1) appFail(409, 'PROJECT_CONFLICT');
        $deleteDocs = $pdo->prepare('DELETE FROM application_documents WHERE project_id=:id');
        $deleteDocs->execute(['id' => $project['id']]);
        $pdo->commit();
        $root = getenv('ALPESEX_APPLICATION_DIR') ?: '/home/www/private/application';
        $pending = 0;
        foreach ($files as $file) {
            $path = $root . '/documents/' . $user['organizationId'] . '/' . basename((string) $file);
            if (is_file($path) && !@unlink($path)) ++$pending;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'cleanupPending' => $pending]);
        exit;
    }

    if ($action === 'documents-status' && $method === 'POST') {
        $input = appJson();
        $project = appProject($pdo, $user['organizationId'], trim((string) ($input['projectId'] ?? '')));
        if ($access->projectAccess($user, $project) === 'none') {
            appFail(403, 'FORBIDDEN');
        }
        $query = $pdo->prepare('SELECT * FROM application_documents WHERE project_id=:project_id');
        $query->execute(['project_id' => $project['id']]);
        $byReference = [];
        foreach ($query->fetchAll() as $document) {
            $byReference[strtoupper((string) $document['reference_code'])] = $document;
        }
        $documents = [];
        foreach (($input['documents'] ?? []) as $requested) {
            $ref = strtoupper(trim((string) ($requested['ref'] ?? '')));
            $document = $byReference[$ref] ?? null;
            $documents[] = $document ? [
                'ref' => $ref, 'state' => 'Disponible', 'fileName' => $document['file_name'],
                'size' => (int) $document['byte_size'], 'modifiedAt' => $document['updated_at'],
            ] : ['ref' => $ref, 'state' => 'Non accessible', 'fileName' => '', 'size' => 0, 'modifiedAt' => ''];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['documents' => $documents], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'document-upload' && $method === 'POST') {
        $pdo->beginTransaction();
        $project = appProject($pdo, $user['organizationId'], trim((string) ($_POST['projectId'] ?? '')), true);
        if ($access->projectAccess($user, $project) !== 'editor') {
            appFail(403, 'READ_ONLY');
        }
        $file = $_FILES['file'] ?? null;
        $ref = strtoupper(trim((string) ($_POST['ref'] ?? '')));
        $family = strtoupper(trim((string) ($_POST['family'] ?? '')));
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $ref === '') {
            appFail(422, 'DOCUMENT_INVALID');
        }
        $size = (int) $file['size'];
        if ($size < 1 || $size > 1024 * 1024) {
            appFail(413, 'DOCUMENT_TOO_LARGE');
        }
        $existing = $pdo->prepare('SELECT id,storage_name FROM application_documents WHERE project_id=:project_id AND reference_code=:reference_code LIMIT 1');
        $existing->execute(['project_id' => $project['id'], 'reference_code' => $ref]);
        $old = $existing->fetch();
        $id = is_array($old) ? (string) $old['id'] : appUuid();
        $storageRoot = getenv('ALPESEX_APPLICATION_DIR') ?: '/home/www/private/application';
        $directory = $storageRoot . '/documents/' . $user['organizationId'];
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            appFail(503, 'STORAGE_UNAVAILABLE');
        }
        $storageName = bin2hex(random_bytes(32));
        $target = $directory . '/' . $storageName;
        $newUploadPath = $target;
        $plainDocument = file_get_contents((string) $file['tmp_name']);
        if (!is_string($plainDocument) || file_put_contents($target, $cipher->encryptBytes($plainDocument, 'document:' . $id)) === false) {
            @unlink($target);
            appFail(503, 'STORAGE_UNAVAILABLE');
        }
        chmod($target, 0600);
        $statement = $pdo->prepare(
            'INSERT INTO application_documents
             (id,organization_id,project_id,reference_code,family_code,file_name,media_type,byte_size,storage_name,uploaded_by_user_id)
             VALUES (:id,:organization_id,:project_id,:reference_code,:family_code,:file_name,:media_type,:byte_size,:storage_name,:user_id)
             ON DUPLICATE KEY UPDATE family_code=VALUES(family_code),file_name=VALUES(file_name),media_type=VALUES(media_type),
             byte_size=VALUES(byte_size),storage_name=VALUES(storage_name),uploaded_by_user_id=VALUES(uploaded_by_user_id),updated_at=UTC_TIMESTAMP()'
        );
        $statement->execute([
            'id' => $id, 'organization_id' => $user['organizationId'], 'project_id' => $project['id'],
            'reference_code' => $ref, 'family_code' => $family ?: null,
            'file_name' => mb_substr(basename((string) $file['name']), 0, 255),
            'media_type' => mb_substr((string) ($file['type'] ?: 'application/octet-stream'), 0, 150),
            'byte_size' => $size, 'storage_name' => $storageName, 'user_id' => $user['id'],
        ]);
        $pdo->commit();
        unset($newUploadPath);
        if (is_array($old) && $old['storage_name'] !== $storageName) {
            @unlink($directory . '/' . basename((string) $old['storage_name']));
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'fileName' => basename((string) $file['name']), 'size' => $size], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'document-open' && $method === 'GET') {
        $project = appProject($pdo, $user['organizationId'], trim((string) ($_GET['projectId'] ?? '')));
        if ($access->projectAccess($user, $project) === 'none') {
            appFail(403, 'FORBIDDEN');
        }
        $query = $pdo->prepare('SELECT * FROM application_documents WHERE project_id=:project_id AND reference_code=:reference_code LIMIT 1');
        $query->execute(['project_id' => $project['id'], 'reference_code' => strtoupper(trim((string) ($_GET['ref'] ?? '')))]);
        $document = $query->fetch();
        $root = getenv('ALPESEX_APPLICATION_DIR') ?: '/home/www/private/application';
        $file = is_array($document) ? $root . '/documents/' . $user['organizationId'] . '/' . basename((string) $document['storage_name']) : '';
        if (!is_array($document) || !is_file($file)) {
            appFail(404, 'DOCUMENT_NOT_FOUND');
        }
        $plainDocument = $cipher->decryptBytes(file_get_contents($file) ?: '', 'document:' . $document['id']);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($plainDocument));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string) $document['file_name']));
        echo $plainDocument;
        exit;
    }

    appFail(405, 'METHOD_NOT_ALLOWED');
} catch (JsonException) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($newUploadPath)) @unlink($newUploadPath);
    appFail(400, 'INVALID_JSON');
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($newUploadPath)) @unlink($newUploadPath);
    appFail(($exception->errorInfo[1] ?? 0) === 1062 ? 409 : 503,
        ($exception->errorInfo[1] ?? 0) === 1062 ? 'PROJECT_CONFLICT' : 'APPLICATION_UNAVAILABLE');
} catch (DeviceAlreadyConnectedException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    appFail(409, 'DEVICE_ALREADY_CONNECTED', ['deviceName' => $exception->deviceName]);
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($newUploadPath)) @unlink($newUploadPath);
    $status = $exception->getCode();
    appFail($status >= 400 && $status <= 599 ? $status : 422, $exception->getMessage());
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($newUploadPath)) @unlink($newUploadPath);
    appFail(500, 'APPLICATION_UNAVAILABLE');
}
