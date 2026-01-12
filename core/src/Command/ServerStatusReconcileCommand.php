<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Job;
use App\Repository\PublicServerRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:status:reconcile',
    description: 'Queue public server status checks for due servers.',
)]
final class ServerStatusReconcileCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly PublicServerRepository $publicServerRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $servers = $this->publicServerRepository->findDueForCheck($now, self::DEFAULT_BATCH_SIZE);
        $queued = 0;

        foreach ($servers as $server) {
            $payload = [
                'server_id' => (string) ($server->getId() ?? ''),
                'ip' => $server->getIp(),
                'port' => (string) $server->getPort(),
                'query_type' => $server->getQueryType(),
                'game_key' => $server->getGameKey(),
            ];

            if ($server->getQueryPort() !== null) {
                $payload['query_port'] = (string) $server->getQueryPort();
            }

            $job = new Job('server.status.check', $payload);
            $this->entityManager->persist($job);

            $nextCheckAt = $now->modify(sprintf('+%d seconds', $server->getCheckIntervalSeconds()));
            $server->setNextCheckAt($nextCheckAt);
            $this->entityManager->persist($server);

            $this->auditLogger->log(null, 'public_server.status_check_queued', [
                'server_id' => $server->getId(),
                'job_id' => $job->getId(),
                'next_check_at' => $nextCheckAt->format(DATE_RFC3339),
            ]);

            $queued++;
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Queued %d server status check(s).', $queued));

        return Command::SUCCESS;
    }
}
