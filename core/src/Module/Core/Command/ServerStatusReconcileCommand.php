<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\PublicServerStatusService;
use App\Repository\PublicServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:gameserver-status:refresh',
    description: 'Queue public gameserver status checks for due public server-directory entries.',
    aliases: ['server:status:reconcile'],
)]
final class ServerStatusReconcileCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly PublicServerRepository $publicServerRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicServerStatusService $statusService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $servers = $this->publicServerRepository->findDueForCheck($now, self::DEFAULT_BATCH_SIZE);
        $queued = $this->statusService->queueDueChecks($servers, self::DEFAULT_BATCH_SIZE, $now);

        foreach ($servers as $server) {
            $this->auditLogger->log(null, 'public_server.status_check_queued', [
                'server_id' => $server->getId(),
                'next_check_at' => $server->getNextCheckAt()?->format(DATE_RFC3339),
            ]);
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Queued %d gameserver status check(s).', $queued));

        return Command::SUCCESS;
    }
}
