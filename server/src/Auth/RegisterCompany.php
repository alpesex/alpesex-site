<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Auth;

use AlpesEx\Portal\Config;
use AlpesEx\Portal\Mail\Mailer;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

final class RegisterCompany
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Mailer $mailer
    ) {
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): void
    {
        $email = strtolower($this->email($input, 'email'));
        $billingEmail = strtolower($this->email($input, 'billingEmail'));
        $password = (string) ($input['password'] ?? '');

        if (strlen($password) < 12) {
            throw new RuntimeException('Le mot de passe doit contenir au moins 12 caractères.');
        }
        if (!$this->accepted($input['terms'] ?? null)) {
            throw new RuntimeException('Vous devez accepter les CGU et la politique de confidentialité.');
        }

        $country = $this->required($input, 'country', 100);
        $siren = preg_replace('/\D/', '', (string) ($input['siren'] ?? '')) ?? '';
        $siret = preg_replace('/\D/', '', (string) ($input['siret'] ?? '')) ?? '';
        if ($country === 'France' && (strlen($siren) !== 9 || strlen($siret) !== 14 || !str_starts_with($siret, $siren))) {
            throw new RuntimeException('Le SIREN ou le SIRET est invalide.');
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

        try {
            $this->pdo->beginTransaction();

            $organization = $this->pdo->prepare(
                'INSERT INTO organizations
                (name, trade_name, legal_form, country, siren, siret, vat_number,
                 billing_address_1, billing_address_2, postal_code, city, billing_email,
                 invoice_platform, terms_accepted_at, status)
                VALUES
                (:name, :trade_name, :legal_form, :country, :siren, :siret, :vat_number,
                 :billing_address_1, :billing_address_2, :postal_code, :city, :billing_email,
                 :invoice_platform, UTC_TIMESTAMP(), :status)'
            );
            $organization->execute([
                'name' => $this->required($input, 'legalName', 150),
                'trade_name' => $this->optional($input, 'tradeName', 150),
                'legal_form' => $this->required($input, 'legalForm', 100),
                'country' => $country,
                'siren' => $siren !== '' ? $siren : null,
                'siret' => $siret !== '' ? $siret : null,
                'vat_number' => $this->optional($input, 'vatNumber', 20),
                'billing_address_1' => $this->required($input, 'billingAddress1', 180),
                'billing_address_2' => $this->optional($input, 'billingAddress2', 150),
                'postal_code' => $this->required($input, 'postalCode', 12),
                'city' => $this->required($input, 'city', 100),
                'billing_email' => $billingEmail,
                'invoice_platform' => $this->optional($input, 'invoicePlatform', 150),
                'status' => 'pending',
            ]);
            $organizationId = (int) $this->pdo->lastInsertId();

            $user = $this->pdo->prepare(
                'INSERT INTO users
                (organization_id, email, password_hash, first_name, last_name, role, status, marketing_opt_in_at)
                VALUES
                (:organization_id, :email, :password_hash, :first_name, :last_name, :role, :status, :marketing_opt_in_at)'
            );
            $user->execute([
                'organization_id' => $organizationId,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $this->required($input, 'firstName', 80),
                'last_name' => $this->required($input, 'lastName', 80),
                'role' => 'manager',
                'status' => 'pending_verification',
                'marketing_opt_in_at' => $this->accepted($input['marketing'] ?? null)
                    ? gmdate('Y-m-d H:i:s')
                    : null,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $statement = $this->pdo->prepare(
                'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at)
                 VALUES (:user_id, :purpose, :token_hash, :expires_at)'
            );
            $statement->execute([
                'user_id' => $userId,
                'purpose' => 'email_verification',
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Cette adresse e-mail ou ce SIRET est déjà enregistré.', 0, $exception);
            }
            throw $exception;
        }

        $firstName = $this->required($input, 'firstName', 80);
        $confirmationUrl = rtrim($this->config->string('APP_URL'), '/')
            . '/api/auth/confirm-email?token=' . rawurlencode($token);
        $safeName = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($confirmationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->mailer->send(
            $email,
            "Confirmez votre adresse e-mail ALPES'Ex",
            "<p>Bonjour {$safeName},</p><p>Votre organisation ALPES'Ex a bien été créée.</p><p><a href=\"{$safeUrl}\">Confirmer mon adresse e-mail</a></p><p>Ce lien est valable pendant 24 heures.</p><p>Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.</p>",
            "Bonjour {$firstName},\n\nConfirmez votre adresse e-mail : {$confirmationUrl}\n\nCe lien est valable pendant 24 heures."
        );
    }

    /** @param array<string, mixed> $input */
    private function required(array $input, string $key, int $maxLength): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new RuntimeException("Champ invalide : {$key}");
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private function optional(array $input, string $key, int $maxLength): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if (mb_strlen($value) > $maxLength) {
            throw new RuntimeException("Champ invalide : {$key}");
        }
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $input */
    private function email(array $input, string $key): string
    {
        $value = $this->required($input, $key, 254);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException("Adresse e-mail invalide : {$key}");
        }
        return $value;
    }

    private function accepted(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'true'], true);
    }
}
