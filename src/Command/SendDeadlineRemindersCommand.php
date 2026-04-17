<?php

namespace App\Command;

use App\Repository\TacheRepository;
use App\Service\TaskNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:task:send-deadline-reminders',
    description: 'Envoie des emails de rappel pour les taches dont la date limite approche',
)]
class SendDeadlineRemindersCommand extends Command
{
    public function __construct(
        private TacheRepository $tacheRepository,
        private TaskNotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Nombre de jours avant echeance pour envoyer le rappel', 3);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        $today = new \DateTime('today');
        $deadline = (new \DateTime('today'))->modify('+' . $days . ' days');

        // Taches non terminees dont la date limite est entre aujourd'hui et aujourd'hui + N jours
        $taches = $this->tacheRepository->createQueryBuilder('t')
            ->leftJoin('t.employe', 'e')
            ->addSelect('e')
            ->leftJoin('t.projet', 'p')
            ->addSelect('p')
            ->where('t.date_limite BETWEEN :today AND :deadline')
            ->andWhere('t.statut_tache != :terminee')
            ->setParameter('today', $today->format('Y-m-d'))
            ->setParameter('deadline', $deadline->format('Y-m-d'))
            ->setParameter('terminee', 'TERMINEE')
            ->getQuery()
            ->getResult();

        $sent = 0;
        foreach ($taches as $tache) {
            if ($tache->getEmploye() === null || empty($tache->getEmploye()->getEmail())) {
                continue;
            }

            $dateLimite = $tache->getDateLimite();
            $daysRemaining = $dateLimite ? (int) $today->diff($dateLimite)->format('%r%a') : 0;

            $this->notificationService->notifyDeadlineApproaching($tache, max(0, $daysRemaining));
            $sent++;

            $io->text(sprintf(
                '  → %s (%s) — echeance dans %d jour(s)',
                $tache->getTitre(),
                $tache->getEmploye()->getEmail(),
                $daysRemaining
            ));
        }

        $io->success(sprintf('%d rappel(s) envoye(s) pour les taches a echeance dans %d jour(s).', $sent, $days));

        return Command::SUCCESS;
    }
}
