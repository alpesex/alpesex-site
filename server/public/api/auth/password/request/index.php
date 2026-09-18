<?php

declare(strict_types=1);

use AlpesEx\Portal\Auth\RequestPasswordReset;
use AlpesEx\Portal\Database;
use AlpesEx\Portal\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('Corps de requête invalide.');
    }

    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__, 5) . '/app';
    $services = require $appDirectory . '/bootstrap.php';
    $pdo = Database::connect($services['config']);

    (new RateLimiter($pdo))->assertAllowed(
        'password_reset_request',
        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $email,
        5,
        3600
    );

    (new RequestPasswordReset($pdo, $services['config'], $services['mailer']))->execute($email);

    echo json_encode([
        'message' => 'Si cette adresse correspond à un compte actif, un lien temporaire vient d’être envoyé.',
    ], JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['message' => 'Données JSON invalides.'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    $limited = str_starts_with($exception->getMessage(), 'Trop de tentatives');
    http_response_code($limited ? 429 : 422);
    echo json_encode([
        'message' => $limited
            ? $exception->getMessage()
            : 'Si cette adresse correspond à un compte actif, un lien temporaire vient d’être envoyé.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'message' => 'Une erreur interne empêche momentanément l’envoi du lien.',
    ], JSON_UNESCAPED_UNICODE);
}
