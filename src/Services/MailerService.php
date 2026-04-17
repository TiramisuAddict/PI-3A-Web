<?php

namespace App\Services;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class MailerService
{
    public function __construct(private string $mailerFromAddress)
    {
    }

    public function sendTemporaryPassword(
        MailerInterface $mailer,
        string $toEmail,
        string $prenom,
        string $nom,
        string $plainPassword
    ): void {
        $email = (new Email())
            ->from($this->mailerFromAddress)
            ->to($toEmail)
            ->subject('Vos identifiants de connexion')
            ->text(sprintf(
                "Bonjour %s %s,\n\nVotre compte a ete cree.\nMot de passe temporaire: %s\n\nMerci de le changer apres votre premiere connexion.",
                $prenom,
                $nom,
                $plainPassword
            ));

        $mailer->send($email);
    }
}