<?php

declare(strict_types=1);

namespace App\Module\Nodes\Command;

use App\Module\Nodes\Application\Proxmox\ProxmoxManager;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:proxmox:sync-tasks', description: 'Refresh tracked Proxmox task states without an open browser.')]
final class ProxmoxTaskSyncCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ProxmoxManager $manager)
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
        $tasks = $this->em->createQueryBuilder()->select('task')->from(ProxmoxTask::class, 'task')->join('task.cluster', 'cluster')->where('task.state IN (:states)')->andWhere('cluster.enabled = true')->setParameter('states', ['submitting', 'running'])->orderBy('task.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
        $errors = 0;
        $checked = 0;
        foreach ($tasks as $task) {
            if (!$task->getCluster()->isEnabled()) {
                continue;
            }
            try {
                $this->manager->refreshTask($task);
                ++$checked;
            } catch (\Throwable) {
                ++$errors;
                $output->writeln('<error>Task '.$task->getId().': status currently unavailable.</error>');
            }
        }
        $output->writeln(sprintf('Checked %d Proxmox task(s); %d unavailable.', $checked, $errors));

        return 0 === $errors ? Command::SUCCESS : Command::FAILURE;
    }
}
