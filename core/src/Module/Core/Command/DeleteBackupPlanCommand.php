<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\Backup\WritableBackupPlanStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backup-plans:delete', description: 'Remove a scheduled backup plan; existing archives and run records are preserved.')]
final class DeleteBackupPlanCommand extends Command
{
    public function __construct(private readonly WritableBackupPlanStoreInterface $store)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Backup plan ID.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        if ($id === '') {
            return self::INVALID;
        }
        try {
            $deleted = $this->store->delete($id);
        } catch (\Throwable) {
            $output->writeln('<error>Unable to delete backup plan. Check the database connection and migrations.</error>');

            return self::FAILURE;
        }
        $output->writeln($deleted ? 'Backup plan deleted. Existing archives are preserved.' : 'Backup plan does not exist.');

        return self::SUCCESS;
    }
}
