<?php

namespace App\Service;

use App\Entity\Tache;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;

class TaskNotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $senderEmail = 'noreply@gestion-projet.local',
    ) {
    }

    /**
     * Envoie un email a l'employe assigne pour l'informer d'une nouvelle tache.
     */
    public function notifyNewTask(Tache $tache): void
    {
        $employe = $tache->getEmploye();
        if ($employe === null || empty($employe->getEmail())) {
            return;
        }

        $projet = $tache->getProjet();
        $projetNom = $projet ? $projet->getNom() : 'N/A';
        $dateLimite = $tache->getDateLimite() ? $tache->getDateLimite()->format('d/m/Y') : 'Non definie';

        $email = (new Email())
            ->from($this->senderEmail)
            ->to($employe->getEmail())
            ->subject('Nouvelle tache assignee : ' . $tache->getTitre())
            ->html(
                '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">'
                . '<div style="background:#4A5DEF;color:#fff;padding:20px 24px;border-radius:8px 8px 0 0;">'
                . '<h2 style="margin:0;font-size:18px;">Nouvelle tache assignee</h2>'
                . '</div>'
                . '<div style="background:#f8fafc;padding:24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;">'
                . '<p>Bonjour <strong>' . htmlspecialchars($employe->getPrenom() . ' ' . $employe->getNom()) . '</strong>,</p>'
                . '<p>Une nouvelle tache vous a ete assignee :</p>'
                . '<table style="width:100%;border-collapse:collapse;margin:16px 0;">'
                . '<tr><td style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;font-weight:600;width:140px;">Tache</td>'
                . '<td style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;">' . htmlspecialchars($tache->getTitre()) . '</td></tr>'
                . '<tr><td style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;font-weight:600;">Projet</td>'
                . '<td style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;">' . htmlspecialchars($projetNom) . '</td></tr>'
                . '<tr><td style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;font-weight:600;">Priorite</td>'
                . '<td style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;">' . htmlspecialchars($tache->getPriorite() ?? '-') . '</td></tr>'
                . '<tr><td style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;font-weight:600;">Date limite</td>'
                . '<td style="padding:8px 12px;background:#fff;border:1px solid #e2e8f0;">' . $dateLimite . '</td></tr>'
                . '</table>'
                . '<p style="color:#64748b;font-size:13px;">Connectez-vous a la plateforme pour voir les details.</p>'
                . '</div>'
                . '</div>'
            );

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Echec envoi email nouvelle tache: ' . $e->getMessage());
        }
    }
}
