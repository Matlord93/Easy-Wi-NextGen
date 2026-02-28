<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\Backup\BackupPlanStoreInterface;
use App\Module\Core\Application\Backup\BackupScheduleDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:dispatch-schedules', description: 'Dispatches due backup schedules to messenger.')]
final class DispatchBackupSchedulesCommand extends Command
{
    public function __construct(
        private readonly BackupPlanStoreInterface $planStore,
        private readonly BackupScheduleDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plans = $this->planStore->all();
        $count = $this->dispatcher->dispatchDue($plans);
        $output->writeln(sprintf('Dispatched %d backup schedule(s).', $count));

        return self::SUCCESS;
    }
}
