<?php

namespace App\Service;

use App\Entity\Demande;
use App\Repository\EmployeRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

class DemandeMailer
{
    private const DEFAULT_FROM = 'no-reply@pidev.local';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EmployeRepository $employeRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function notifyManagersDemandeCreated(Demande $demande): void
    {
        $this->notifyManagers($demande, 'created');
    }

    public function notifyManagersDemandeCanceled(Demande $demande): void
    {
        $this->notifyManagers($demande, 'canceled');
    }

    public function notifyEmployeStatusChanged(
        Demande $demande,
        ?string $oldStatus,
        ?string $newStatus,
        string $actor,
        ?string $commentaire = null
    ): void {
        $recipient = trim((string) $demande->getEmploye()?->getEmail());
        if ($recipient === '') {
            $this->logger->warning('Email employe manquant pour notification de statut de demande.', [
                'event' => 'status_changed',
            ]);
            return;
        }

        $subject = sprintf(
            'Mise a jour du statut de votre demande: %s -> %s',
            $oldStatus ?? 'Inconnu',
            $newStatus ?? 'Inconnu'
        );

        $email = (new TemplatedEmail())
            ->from($this->getFromAddress())
            ->to($recipient)
            ->subject($subject)
            ->htmlTemplate('email/demande/employe_status_update.html.twig')
            ->context([
                'demande' => $demande,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
                'actor' => $actor,
                'commentaire' => trim((string) $commentaire),
            ]);

        $this->sendSafely($email, [
            'event' => 'status_changed',
            'recipient' => $recipient,
        ]);
    }

    private function notifyManagers(Demande $demande, string $event): void
    {
        $entrepriseId = $demande->getEmploye()?->getEntreprise()?->getId_entreprise();
        if (null === $entrepriseId) {
            $this->logger->warning('Entreprise introuvable pour notification de demande.', [
                'event' => $event,
            ]);
            return;
        }

        $recipients = $this->employeRepository->findDemandeManagerEmailsByEntrepriseId($entrepriseId);
        if ($recipients === []) {
            $this->logger->warning('Aucun destinataire RH/Admin entreprise trouve pour notification de demande.', [
                'event' => $event,
                'entreprise_id' => $entrepriseId,
            ]);
            return;
        }

        $actionLabel = $event === 'created' ? 'Nouvelle demande creee' : 'Demande annulee';
        $titre = trim($demande->getTitre());

        $email = (new TemplatedEmail())
            ->from($this->getFromAddress())
            ->to(...$recipients)
            ->subject(sprintf('%s: %s', $actionLabel, '' !== $titre ? $titre : 'Demande'))
            ->htmlTemplate('email/demande/rh_admin_event.html.twig')
            ->context([
                'demande' => $demande,
                'event' => $event,
                'actionLabel' => $actionLabel,
                'ownerEmail' => $demande->getEmploye()->getEmail() ?? '',
            ]);

        $this->sendSafely($email, [
            'event' => $event,
            'entreprise_id' => $entrepriseId,
            'recipient_count' => count($recipients),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function sendSafely(TemplatedEmail $email, array $context): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Echec envoi email demande (transport).', $context + [
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Echec envoi email demande.', $context + [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function getFromAddress(): string
    {
        $configured = trim((string) ($_ENV['MAILER_FROM'] ?? ''));
        return $configured !== '' ? $configured : self::DEFAULT_FROM;
    }
}
