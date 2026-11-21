<?php

declare(strict_types=1);

namespace App\Module\Nodes\Command;

use App\Module\Nodes\Application\Proxmox\ProxmoxTaskSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:proxmox:sync-tasks', description: 'Refresh tracked Proxmox task states without an open browser.')]
final class ProxmoxTaskSyncCommand extends Command
{
    public function __construct(private readonly ProxmoxTaskSynchronizer $tasks)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum tasks per pass (1-1000).', '25');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (false === $limit || $limit < 1 || $limit > 1000) {
            $output->writeln('<error>Limit must be between 1 and 1000.</error>');

            return Command::INVALID;
        }
        $result = $this->tasks->run($limit);
        $output->writeln(sprintf('Checked %d Proxmox task(s); %d unavailable.', $result['checked'], $result['errors']));

        return 0 === $result['errors'] ? Command::SUCCESS : Command::FAILURE;
    }
}
