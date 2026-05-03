<?php

namespace App\EventSubscriber;

use App\Entity\InscriptionFormation;
use App\Repository\InscriptionFormationRepository;
use CalendarBundle\CalendarEvents;
use CalendarBundle\Entity\Event;
use CalendarBundle\Event\CalendarEvent;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class InscriptionCalendarSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly InscriptionFormationRepository $inscriptionFormationRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CalendarEvents::SET_DATA => 'onCalendarSetData',
        ];
    }

    public function onCalendarSetData(CalendarEvent $calendar): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $session = $request->getSession();
        $employeeId = (int) $session->get('employe_id', 0);
        if ($employeeId <= 0) {
            return;
        }

        /** @var InscriptionFormation[] $inscriptions */
        $inscriptions = $this->inscriptionFormationRepository->findBy(['employeeId' => $employeeId]);

        foreach ($inscriptions as $inscription) {
            try {
                $formation = $inscription->getFormation();
            } catch (EntityNotFoundException) {
                continue;
            }

            if ($formation === null) {
                continue;
            }

            $title = $formation->getTitre();
            $start = $formation->getDateDebut();
            $end = $formation->getDateFin();
            if ($end !== null) {
                $end = $end->modify('+1 day');
            }

            $event = new Event($title, $start, $end);
            $event->setAllDay(true);
            $event->addOption('backgroundColor', $this->resolveStatusColor($inscription->getStatut()));
            $event->addOption('borderColor', $this->resolveStatusColor($inscription->getStatut()));
            $event->addOption('textColor', '#ffffff');
            $event->addOption('extendedProps', [
                'statut' => $inscription->getStatut(),
                'organisme' => $formation->getOrganisme(),
                'lieu' => $formation->getLieu(),
            ]);

            $calendar->addEvent($event);
        }
    }

    private function resolveStatusColor(string $status): string
    {
        return match ($status) {
            'ACCEPTEE' => '#198754',
            'EN_ATTENTE' => '#f59f00',
            'REFUSEE' => '#dc3545',
            default => '#0d6efd',
        };
    }
}
