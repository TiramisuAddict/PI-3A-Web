<?php

namespace App\Service;

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
                "Bonjour %s %s,\n\nVotre compte a ete cree.\nMot de passe: %s\n\nMerci de le changer apres votre premiere connexion.",
                $prenom,
                $nom,
                $plainPassword
            ));

        $mailer->send($email);
    }

    public function sendPasswordResetVerificationLink(
        MailerInterface $mailer,
        string $toEmail,
        string $verificationLink
    ): void {
        $email = (new Email())
            ->from($this->mailerFromAddress)
            ->to($toEmail)
            ->subject('Verification de reinitialisation du mot de passe')
            ->html(sprintf(
                '<p>Bonjour,</p><p>Vous avez demande une reinitialisation de mot de passe.</p><p><a href="%s" style="display:inline-block;padding:10px 16px;background:#4254D6;color:#fff;text-decoration:none;border-radius:6px;">Verifier</a></p><p>Si vous n\'etes pas a l\'origine de cette demande, ignorez ce message.</p>',
                htmlspecialchars($verificationLink, ENT_QUOTES)
            ));

        $mailer->send($email);
    }
}