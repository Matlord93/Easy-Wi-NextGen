<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\MetricsRetentionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:metrics:cleanup', description: 'Delete old metric rows in bounded batches.')]
final class MetricsCleanupCommand extends Command
{
    public function __construct(
        private readonly MetricsRetentionService $retention,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override configured system/instance retention in days.')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch size per delete statement.', '5000');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawDays = $input->getOption('days');
        $days = null;
        if ($rawDays !== null) {
            $days = filter_var($rawDays, FILTER_VALIDATE_INT);
            if (!is_int($days) || $days < 1 || $days > 365) {
                $io->error('Days must be between 1 and 365.');
                return Command::INVALID;
            }
        }
        $batch = filter_var($input->getOption('batch'), FILTER_VALIDATE_INT);
        if (!is_int($batch) || $batch < 1 || $batch > 5000) {
            $io->error('Days must be between 1 and 365; batch must be between 1 and 5000.');
            return Command::INVALID;
        }
        $summary = $this->retention->cleanup(null, $days, $batch);
        $this->logger->info('metrics.cleanup.completed', $summary);
        $io->success(sprintf('Metrics cleanup finished. Deleted %d expired rows in bounded batches.', array_sum($summary)));

        return Command::SUCCESS;
    }

}
