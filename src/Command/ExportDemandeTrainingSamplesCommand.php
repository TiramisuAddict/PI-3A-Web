<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DemandeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:demande:export-training-samples',
    description: 'Export classification training samples from database to JSON file.',
)]
class ExportDemandeTrainingSamplesCommand extends Command
{
    public function __construct(
        private readonly DemandeRepository $demandeRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of samples to export', 1200)
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output JSON file path', 'var/ai/classification_training_samples.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(100, (int) $input->getOption('limit'));
        $outputPath = (string) $input->getOption('output');
        if ('' === trim($outputPath)) {
            $outputPath = 'var/ai/classification_training_samples.json';
        }

        $samples = $this->demandeRepository->fetchClassificationTrainingSamples($limit);

        $filesystem = new Filesystem();
        $absoluteOutput = $this->toAbsolutePath($outputPath);
        $filesystem->mkdir(dirname($absoluteOutput));
        $filesystem->dumpFile(
            $absoluteOutput,
            (string) json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $output->writeln(sprintf('<info>Exported %d samples to %s</info>', count($samples), $absoluteOutput));

        return Command::SUCCESS;
    }

    private function toAbsolutePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:\\\\|^\//', $path) === 1) {
            return $path;
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
