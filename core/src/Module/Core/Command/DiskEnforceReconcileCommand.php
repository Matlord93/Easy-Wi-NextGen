<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\InstanceDiskState;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Repository\InstanceRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\InstanceDiskStateResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'disk:enforce:reconcile',
    description: 'Evaluate instance disk states and enforce hard blocks.',
)]
final class DiskEnforceReconcileCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceDiskStateResolver $diskStateResolver,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $instances = $this->instanceRepository->findBy([], ['updatedAt' => 'DESC']);
        $stateChanges = 0;
        $hardBlocks = 0;

        foreach ($instances as $instance) {
            $previousState = $instance->getDiskState();
            $nextState = $this->diskStateResolver->resolveState($instance);

            if ($previousState !== $nextState) {
                $instance->setDiskState($nextState);
                $this->entityManager->persist($instance);
                $this->auditLogger->log(null, 'instance.disk.state_changed', [
                    'instance_id' => $instance->getId(),
                    'node_id' => $instance->getNode()->getId(),
                    'previous_state' => $previousState->value,
                    'state' => $nextState->value,
                    'disk_used_bytes' => $instance->getDiskUsedBytes(),
                    'disk_limit_bytes' => $instance->getDiskLimitBytes(),
                ]);
                $stateChanges++;
            }

            if ($nextState === InstanceDiskState::HardBlock && $previousState !== InstanceDiskState::HardBlock) {
                $job = new Job('instance.stop', [
                    'agent_id' => $instance->getNode()->getId(),
                    'instance_id' => (string) ($instance->getId() ?? ''),
                ]);
                $this->entityManager->persist($job);

                $previousStatus = $instance->getStatus();
                $instance->setStatus(InstanceStatus::Suspended);
                $this->entityManager->persist($instance);

                $this->auditLogger->log(null, 'instance.disk.hard_block_enforced', [
                    'instance_id' => $instance->getId(),
                    'node_id' => $instance->getNode()->getId(),
                    'job_id' => $job->getId(),
                    'previous_status' => $previousStatus->value,
                    'status' => InstanceStatus::Suspended->value,
                ]);
                $hardBlocks++;
            }
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Updated %d instance disk state(s).', $stateChanges));
        $output->writeln(sprintf('Enforced %d hard block(s).', $hardBlocks));

        return Command::SUCCESS;
    }
}
