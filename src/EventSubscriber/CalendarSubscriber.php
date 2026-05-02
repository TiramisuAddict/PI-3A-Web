<?php

namespace App\EventSubscriber;

use App\Repository\TacheRepository;
use CalendarBundle\CalendarEvents;
use CalendarBundle\Entity\Event;
use CalendarBundle\Event\CalendarEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CalendarSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TacheRepository $tacheRepository,
        private UrlGeneratorInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CalendarEvents::SET_DATA => 'onCalendarSetData',
        ];
    }

    public function onCalendarSetData(CalendarEvent $calendarEvent): void
    {
        $start = $calendarEvent->getStart();
        $end = $calendarEvent->getEnd();
        $filters = $calendarEvent->getFilters();

        $projetId = $filters['projet'] ?? null;

        $qb = $this->tacheRepository->createQueryBuilder('t')
            ->leftJoin('t.employe', 'e')
            ->addSelect('e')
            ->leftJoin('t.projet', 'p')
            ->addSelect('p')
            ->where('t.date_limite BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('t.date_limite', 'ASC');

        if ($projetId !== null) {
            $qb->andWhere('p.id_projet = :projetId')
               ->setParameter('projetId', (int) $projetId);
        }

        $taches = $qb->getQuery()->getResult();

        // Couleurs alignées sur la palette Tabler
        $statusColors = [
            'A_FAIRE'  => '#667382', // tblr-secondary
            'EN_COURS' => '#4263eb', // tblr-primary
            'BLOCQUEE' => '#d63939', // tblr-danger
            'TERMINEE' => '#2fb344', // tblr-success
        ];

        $priorityColors = [
            'HAUTE'   => '#d63939', // tblr-danger
            'MOYENNE' => '#f76707', // tblr-orange/warning
            'BASSE'   => '#2fb344', // tblr-success
        ];

        foreach ($taches as $tache) {
            $statut = $tache->getStatutTache() ?? 'A_FAIRE';
            $priorite = $tache->getPriorite() ?? 'BASSE';
            $bgColor = $statusColors[$statut] ?? '#64748b';
            $borderColor = $priorityColors[$priorite] ?? '#94a3b8';

            $event = new Event(
                $tache->getTitre(),
                $tache->getDateLimite(),
            );

            $event->setAllDay(true);

            $employe = $tache->getEmploye();
            $employeNom = $employe ? $employe->getNom() . ' ' . $employe->getPrenom() : 'Non assigné';

            $showUrl = '';
            if ($tache->getProjet()) {
                $showUrl = $this->router->generate('app_tache_show', [
                    'id_projet' => $tache->getProjet()->getIdProjet(),
                    'id_tache'  => $tache->getIdTache(),
                ]);
            }

            $event->setOptions([
                'id'              => $tache->getIdTache(),
                'backgroundColor' => $bgColor,
                'borderColor'     => $borderColor,
                'textColor'       => '#ffffff',
                'url'             => $showUrl,
                'extendedProps'   => [
                    'statut'      => $statut,
                    'priorite'    => $priorite,
                    'employe'     => $employeNom,
                    'progression' => $tache->getProgression() ?? 0,
                    'projetNom'   => $tache->getProjet()?->getNom() ?? '',
                ],
            ]);

            $calendarEvent->addEvent($event);
        }
    }
}
