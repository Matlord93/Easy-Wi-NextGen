<?php

declare(strict_types=1);

namespace App\Module\Nodes\Command;

use App\Module\Nodes\Application\Monitoring\InfrastructureCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:infrastructure:collect', description: 'Persist VM/node monitoring and evaluate configured alerts.')]
final class InfrastructureMonitoringCommand extends Command
{
    public function __construct(private readonly InfrastructureCollector $collector)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Collect even if this minute was already processed.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode($this->collector->run($input->getOption('force')), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
