<?php

declare(strict_types=1);

namespace App\Module\Unifi\Command;

use App\Module\Unifi\Application\UnifiSyncOutboxWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:unifi:drain-sync', description: 'Process committed pending UniFi port synchronizations.')]
final class DrainUnifiSyncCommand extends Command
{
    public function __construct(private readonly UnifiSyncOutboxWorker $worker)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum requests per invocation (1–100).', '20');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            $output->writeln('<error>Limit must be between 1 and 100.</error>');
            return Command::INVALID;
        }
        $result = $this->worker->drain($limit);
        $output->writeln(sprintf('Attempted %d; synchronized %d; retry pending %d; disabled/deferred %d.', $result['attempted'], $result['succeeded'], $result['failed'], $result['skipped']));
        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
