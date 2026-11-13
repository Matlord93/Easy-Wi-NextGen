<?php

declare(strict_types=1);

namespace App\Module\Nodes\Command;

use App\Module\Nodes\Application\Monitoring\InfrastructureHistoryStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:infrastructure:archive', description: 'Build bounded batches of hourly monitoring statistics without deleting raw samples.')]
final class InfrastructureArchiveCommand extends Command
{
    public function __construct(private readonly InfrastructureHistoryStore $history)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Hours per batch (1–1000).', '100');
        $this->addOption('batches', null, InputOption::VALUE_REQUIRED, 'Maximum batches per invocation (1–100).', '1');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $size = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT);
        $batches = filter_var($input->getOption('batches'), FILTER_VALIDATE_INT);
        if (false === $size || $size < 1 || $size > 1000 || false === $batches || $batches < 1 || $batches > 100) {
            $output->writeln('Use batch-size 1–1000 and batches 1–100.');

            return Command::INVALID;
        }
        $created = 0;
        $pending = true;
        for ($i = 0; $i < $batches && $pending; ++$i) {
            $result = $this->history->backfill($size);
            $created += $result['created'];
            $pending = $result['pending'];
        }
        $output->writeln(json_encode(['created' => $created, 'pending' => $pending], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
