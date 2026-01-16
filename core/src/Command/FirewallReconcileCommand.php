<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Job;
use App\Repository\FirewallStateRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:firewall:reconcile',
    description: 'Reconcile desired firewall ports and enqueue fixes.'
)]
final class FirewallReconcileCommand extends Command
{
    public function __construct(
        private readonly PortBlockRepository $portBlockRepository,
        private readonly FirewallStateRepository $firewallStateRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $desiredByAgent = [];
        foreach ($this->portBlockRepository->findAssignedBlocks() as $block) {
            $agentId = $block->getPool()->getNode()->getId();
            if (!isset($desiredByAgent[$agentId])) {
                $desiredByAgent[$agentId] = [];
            }
            foreach ($block->getPorts() as $port) {
                $desiredByAgent[$agentId][$port] = true;
            }
        }

        $currentByAgent = [];
        foreach ($this->firewallStateRepository->findAll() as $state) {
            $ports = [];
            foreach ($state->getPorts() as $port) {
                $ports[(int) $port] = true;
            }
            $currentByAgent[$state->getNode()->getId()] = $ports;
        }

        $agentIds = array_unique(array_merge(array_keys($desiredByAgent), array_keys($currentByAgent)));
        $openJobs = 0;
        $closeJobs = 0;

        foreach ($agentIds as $agentId) {
            $desiredPorts = array_keys($desiredByAgent[$agentId] ?? []);
            $currentPorts = array_keys($currentByAgent[$agentId] ?? []);
            sort($desiredPorts);
            sort($currentPorts);

            $toOpen = array_values(array_diff($desiredPorts, $currentPorts));
            $toClose = array_values(array_diff($currentPorts, $desiredPorts));

            if ($toOpen !== []) {
                $job = new Job('firewall.open_ports', [
                    'agent_id' => $agentId,
                    'ports' => implode(',', array_map('strval', $toOpen)),
                ]);
                $this->entityManager->persist($job);
                $this->auditLogger->log(null, 'firewall.reconcile.open_ports', [
                    'agent_id' => $agentId,
                    'ports' => $toOpen,
                    'job_id' => $job->getId(),
                ]);
                $openJobs++;
            }

            if ($toClose !== []) {
                $job = new Job('firewall.close_ports', [
                    'agent_id' => $agentId,
                    'ports' => implode(',', array_map('strval', $toClose)),
                ]);
                $this->entityManager->persist($job);
                $this->auditLogger->log(null, 'firewall.reconcile.close_ports', [
                    'agent_id' => $agentId,
                    'ports' => $toClose,
                    'job_id' => $job->getId(),
                ]);
                $closeJobs++;
            }
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Queued %d open_ports job(s) and %d close_ports job(s).', $openJobs, $closeJobs));

        return Command::SUCCESS;
    }
}
