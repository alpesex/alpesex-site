<?php

declare(strict_types=1);

namespace AlpesEx\Portal\Mail;

use AlpesEx\Portal\Config;
use InvalidArgumentException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

final class Mailer
{
    public function __construct(private readonly Config $config)
    {
    }

    public function send(
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody
    ): void {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Adresse destinataire invalide.');
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->config->string('SMTP_HOST');
            $mail->Port = $this->config->integer('SMTP_PORT');
            $mail->SMTPAuth = true;
            $mail->Username = $this->config->email('SMTP_USERNAME');
            $mail->Password = $this->config->string('SMTP_PASSWORD');
            $mail->SMTPSecure = $this->encryption();
            $mail->Timeout = 15;

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom(
                $this->config->email('MAIL_FROM_ADDRESS'),
                $this->config->string('MAIL_FROM_NAME')
            );
            $mail->addReplyTo(
                $this->config->email('MAIL_REPLY_TO_ADDRESS'),
                $this->config->string('MAIL_REPLY_TO_NAME')
            );
            $mail->addAddress($recipient);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
            $mail->send();
        } catch (Throwable $error) {
            throw new RuntimeException("L'e-mail n'a pas pu être envoyé.", 0, $error);
        }
    }

    private function encryption(): string
    {
        return match (strtolower($this->config->string('SMTP_ENCRYPTION'))) {
            'smtps', 'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'starttls', 'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => throw new RuntimeException('SMTP_ENCRYPTION doit valoir smtps ou starttls.'),
        };
    }
}
