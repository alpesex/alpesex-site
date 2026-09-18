<?php

declare(strict_types=1);

use AlpesEx\Portal\Database;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    session_name('ALPESEXSESSID');
    session_set_cookie_params([
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId === null) {
        throw new RuntimeException('Session absente ou expirée.');
    }

    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 4) . '/app';
    /** @var array{config: AlpesEx\Portal\Config, mailer: AlpesEx\Portal\Mail\Mailer} $services */
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);

    $statement = $pdo->prepare(
        'SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.status,
                o.id AS organization_id, o.name AS organization_name, o.trade_name,
                o.legal_form, o.country, o.siren, o.siret, o.vat_number,
                o.billing_address_1, o.billing_address_2, o.postal_code, o.city,
                o.billing_email, o.invoice_platform, o.status AS organization_status
         FROM users u
         LEFT JOIN organizations o ON o.id = u.organization_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $record = $statement->fetch();

    if (!is_array($record) || $record['status'] !== 'active') {
        session_destroy();
        throw new RuntimeException('Session absente ou expirée.');
    }

    echo json_encode([
        'user' => [
            'id' => (int) $record['id'],
            'email' => $record['email'],
            'firstName' => $record['first_name'],
            'lastName' => $record['last_name'],
            'role' => $record['role'],
        ],
        'organization' => $record['organization_id'] === null ? null : [
            'id' => (int) $record['organization_id'],
            'name' => $record['organization_name'],
            'tradeName' => $record['trade_name'],
            'legalForm' => $record['legal_form'],
            'country' => $record['country'],
            'siren' => $record['siren'],
            'siret' => $record['siret'],
            'vatNumber' => $record['vat_number'],
            'billingAddress1' => $record['billing_address_1'],
            'billingAddress2' => $record['billing_address_2'],
            'postalCode' => $record['postal_code'],
            'city' => $record['city'],
            'billingEmail' => $record['billing_email'],
            'invoicePlatform' => $record['invoice_platform'],
            'status' => $record['organization_status'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(401);
    echo json_encode(['message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['message' => 'Une erreur interne empêche le chargement du compte.'], JSON_UNESCAPED_UNICODE);
}
