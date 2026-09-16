<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Mail;

final class TransactionalMailer
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function emailConfirmation(string $recipient, string $firstName, string $confirmationUrl): void
    {
        $name = $this->html($firstName);
        $url = $this->html($confirmationUrl);
        $this->mailer->send(
            $recipient,
            "Confirmez votre adresse e-mail ALPES'Ex",
            "<p>Bonjour {$name},</p><p>Votre organisation ALPES'Ex a bien été créée.</p><p><a href=\"{$url}\">Confirmer mon adresse e-mail</a></p><p>Ce lien est valable pendant 24 heures.</p><p>Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.</p>",
            "Bonjour {$firstName},\n\nConfirmez votre adresse e-mail : {$confirmationUrl}\n\nCe lien est valable pendant 24 heures."
        );
    }

    public function teamInvitation(
        string $recipient,
        string $firstName,
        string $invitationUrl,
        bool $existingAccount = false
    ): void {
        $displayName = $firstName !== '' ? $firstName : 'Bonjour';
        $name = $this->html($displayName);
        $url = $this->html($invitationUrl);
        $action = $existingAccount ? 'Me connecter' : 'Créer mon compte';
        $intro = $existingAccount
            ? "Vous possédez déjà un compte ALPES'Ex. Le gestionnaire vous invite à accéder à son espace."
            : "Le gestionnaire de votre organisation vous invite à rejoindre son espace ALPES'Ex.";
        $validity = $existingAccount ? '' : '<p>Ce lien personnel est valable pendant 7 jours.</p>';
        $plainValidity = $existingAccount ? '' : "\n\nCe lien personnel est valable pendant 7 jours.";

        $this->mailer->send(
            $recipient,
            "Invitation à rejoindre une équipe ALPES'Ex",
            "<p>{$name},</p><p>{$intro}</p><p><a href=\"{$url}\">{$action}</a></p>{$validity}",
            "{$displayName},\n\n{$intro}\n\n{$action} : {$invitationUrl}{$plainValidity}"
        );
    }

    public function passwordReset(string $recipient, string $firstName, string $resetUrl): void
    {
        $name = $this->html($firstName);
        $url = $this->html($resetUrl);
        $this->mailer->send(
            $recipient,
            "Réinitialisation de votre mot de passe ALPES'Ex",
            "<p>Bonjour {$name},</p><p><a href=\"{$url}\">Réinitialiser mon mot de passe</a></p><p>Ce lien est valable pendant 30 minutes et ne peut être utilisé qu’une seule fois.</p><p>Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.</p>",
            "Bonjour {$firstName},\n\nRéinitialisez votre mot de passe : {$resetUrl}\n\nCe lien est valable pendant 30 minutes et ne peut être utilisé qu’une seule fois."
        );
    }

    public function licenseDelivery(string $recipient, string $firstName, string $licenseType, string $downloadUrl): void
    {
        $name = $this->html($firstName);
        $type = $this->html($licenseType);
        $url = $this->html($downloadUrl);
        $this->mailer->send(
            $recipient,
            "Votre licence {$licenseType} ALPES'Ex",
            "<p>Bonjour {$name},</p><p>Votre licence <strong>{$type}</strong> est disponible.</p><p><a href=\"{$url}\">Accéder à mon téléchargement</a></p><p>Ne transmettez pas vos informations de licence à un tiers.</p>",
            "Bonjour {$firstName},\n\nVotre licence {$licenseType} est disponible : {$downloadUrl}"
        );
    }

    public function orderConfirmation(string $managerEmail, string $orderNumber, string $total): void
    {
        $number = $this->html($orderNumber);
        $safeTotal = $this->html($total);
        $this->mailer->send(
            $managerEmail,
            "Confirmation de commande {$orderNumber} – ALPES'Ex",
            "<p>Votre commande <strong>{$number}</strong> est confirmée.</p><p>Montant : <strong>{$safeTotal}</strong>.</p><p>La facture sera adressée au gestionnaire dès son émission.</p>",
            "Votre commande {$orderNumber} est confirmée. Montant : {$total}."
        );
    }

    public function invoiceAvailable(string $managerEmail, string $invoiceNumber, string $invoiceUrl): void
    {
        $number = $this->html($invoiceNumber);
        $url = $this->html($invoiceUrl);
        $this->mailer->send(
            $managerEmail,
            "Facture {$invoiceNumber} disponible – ALPES'Ex",
            "<p>Votre facture <strong>{$number}</strong> est disponible.</p><p><a href=\"{$url}\">Télécharger ma facture</a></p>",
            "Votre facture {$invoiceNumber} est disponible : {$invoiceUrl}"
        );
    }

    public function licenseLimit(string $managerEmail, int $used, int $available): void
    {
        $this->mailer->send(
            $managerEmail,
            "Alerte de limite de licences – ALPES'Ex",
            "<p>Votre organisation utilise actuellement <strong>{$used} licence(s) sur {$available}</strong>.</p><p>Vous pouvez gérer ou compléter vos licences depuis votre espace client.</p>",
            "Votre organisation utilise actuellement {$used} licence(s) sur {$available}."
        );
    }

    public function subscriptionConfirmation(string $managerEmail, string $offerName): void
    {
        $offer = $this->html($offerName);
        $this->mailer->send(
            $managerEmail,
            "Confirmation de votre abonnement ALPES'Ex",
            "<p>Votre abonnement <strong>{$offer}</strong> est maintenant actif.</p>",
            "Votre abonnement {$offerName} est maintenant actif."
        );
    }

    public function paymentFailure(string $managerEmail, string $actionUrl): void
    {
        $url = $this->html($actionUrl);
        $this->mailer->send(
            $managerEmail,
            "Échec de paiement – action requise",
            "<p>Le paiement de votre abonnement ALPES'Ex a échoué.</p><p><a href=\"{$url}\">Mettre à jour mon moyen de paiement</a></p><p>Sans régularisation, l’abonnement pourra être suspendu.</p>",
            "Le paiement de votre abonnement ALPES'Ex a échoué. Régularisez la situation : {$actionUrl}"
        );
    }

    public function trialExpiring(string $managerEmail, string $expirationDate, string $offerUrl): void
    {
        $date = $this->html($expirationDate);
        $url = $this->html($offerUrl);
        $this->mailer->send(
            $managerEmail,
            "Votre essai ALPES'Ex arrive à expiration",
            "<p>Votre période d’essai prendra fin le <strong>{$date}</strong>.</p><p><a href=\"{$url}\">Choisir mon offre</a></p>",
            "Votre période d’essai prendra fin le {$expirationDate}. Choisissez votre offre : {$offerUrl}"
        );
    }

    public function userLicenseAssigned(string $recipient, string $firstName, string $licenseToken): void
    {
        $name = $this->html($firstName);
        $token = $this->html($licenseToken);
        $this->mailer->send(
            $recipient,
            "Votre licence Utilisateur ALPES'Ex",
            "<p>Bonjour {$name},</p><p>Votre gestionnaire vous a affecté une licence Utilisateur ALPES'Ex.</p><p>Clé de licence :</p><p style=\"overflow-wrap:anywhere\"><strong>{$token}</strong></p><p>Conservez cette clé de manière confidentielle.</p>",
            "Bonjour {$firstName},\n\nVotre clé de licence Utilisateur ALPES'Ex :\n{$licenseToken}\n\nConservez cette clé de manière confidentielle."
        );
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
