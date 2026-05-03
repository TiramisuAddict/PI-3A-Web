<?php

namespace App\Command;

use App\Repository\DemandeRepository;
use App\Service\DemandeMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demande:trigger-mail', description: 'Trigger DemandeMailer notifications for a demande')]
class DemandeMailerTriggerCommand extends Command
{
    public function __construct(
        private readonly DemandeRepository $demandeRepository,
        private readonly DemandeMailer $demandeMailer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Demande id (id_demande)')
            ->addOption('action', null, InputOption::VALUE_REQUIRED, 'Action to trigger: created|canceled|status', 'created')
            ->addOption('old', null, InputOption::VALUE_OPTIONAL, 'Old status', 'EN_ATTENTE')
            ->addOption('new', null, InputOption::VALUE_OPTIONAL, 'New status', 'TRAITEE')
            ->addOption('actor', null, InputOption::VALUE_OPTIONAL, 'Actor label', 'admin')
            ->addOption('comment', null, InputOption::VALUE_OPTIONAL, 'Commentaire', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = (int) $input->getArgument('id');
        $action = (string) $input->getOption('action');

        $demande = $this->demandeRepository->find($id);
        if (null === $demande) {
            $io->error(sprintf('Demande with id %d not found.', $id));
            return Command::FAILURE;
        }

        if ($action === 'created') {
            $this->demandeMailer->notifyManagersDemandeCreated($demande);
            $io->success('notifyManagersDemandeCreated called.');
            return Command::SUCCESS;
        }

        if ($action === 'canceled') {
            $this->demandeMailer->notifyManagersDemandeCanceled($demande);
            $io->success('notifyManagersDemandeCanceled called.');
            return Command::SUCCESS;
        }

        if ($action === 'status') {
            $old = (string) $input->getOption('old');
            $new = (string) $input->getOption('new');
            $actor = (string) $input->getOption('actor');
            $comment = $input->getOption('comment') ?? null;

            $this->demandeMailer->notifyEmployeStatusChanged($demande, $old, $new, $actor, $comment);
            $io->success('notifyEmployeStatusChanged called.');
            return Command::SUCCESS;
        }

        $io->error('Unknown action. Use created, canceled, or status.');
        return Command::FAILURE;
    }
}
