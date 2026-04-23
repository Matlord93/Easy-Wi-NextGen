<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\LogIndex;
use App\Repository\LogIndexRepository;
use App\Repository\RetentionPolicyRepository;
use App\Repository\TicketMessageRepository;
use App\Repository\TicketRepository;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gdpr:retention:cleanup', description: 'Apply GDPR retention policies to tickets, logs, and sessions.')]
final class GdprRetentionCleanupCommand extends Command
{
    public function __construct(
        private readonly RetentionPolicyRepository $retentionRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly TicketMessageRepository $ticketMessageRepository,
        private readonly LogIndexRepository $logIndexRepository,
        private readonly UserSessionRepository $userSessionRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $policy = $this->retentionRepository->getCurrent();

        $ticketDays = $policy?->getTicketRetentionDays() ?? 365;
        $logDays = $policy?->getLogRetentionDays() ?? 7;
        $sessionDays = $policy?->getSessionRetentionDays() ?? 30;
        $importantLogSources = [LogIndex::SOURCE_JOB, LogIndex::SOURCE_MAIL];

        $now = new \DateTimeImmutable();
        $ticketCutoff = $now->modify(sprintf('-%d days', $ticketDays));
        $logCutoff = $now->modify(sprintf('-%d days', $logDays));
        $nonCriticalLogCutoff = $now->modify('-48 hours');
        $sessionCutoff = $now->modify(sprintf('-%d days', $sessionDays));

        $deletedTicketMessages = $this->ticketMessageRepository->deleteForClosedTicketsBefore($ticketCutoff);
        $deletedTickets = $this->ticketRepository->deleteClosedBefore($ticketCutoff);
        $deletedImportantLogs = $this->logIndexRepository->deleteOlderThanBySources($logCutoff, $importantLogSources);
        $deletedNonCriticalLogs = $this->logIndexRepository->deleteOlderThanExcludingSources($nonCriticalLogCutoff, $importantLogSources);
        $deletedLogs = $deletedImportantLogs + $deletedNonCriticalLogs;
        $deletedSessions = $this->userSessionRepository->deleteExpiredBefore($sessionCutoff);

        $this->auditLogger->log(null, 'gdpr.retention_cleanup', [
            'ticket_cutoff' => $ticketCutoff->format(DATE_RFC3339),
            'log_cutoff' => $logCutoff->format(DATE_RFC3339),
            'log_non_critical_cutoff' => $nonCriticalLogCutoff->format(DATE_RFC3339),
            'session_cutoff' => $sessionCutoff->format(DATE_RFC3339),
            'deleted' => [
                'ticket_messages' => $deletedTicketMessages,
                'tickets' => $deletedTickets,
                'logs' => [
                    'important' => $deletedImportantLogs,
                    'non_critical' => $deletedNonCriticalLogs,
                    'total' => $deletedLogs,
                ],
                'sessions' => $deletedSessions,
            ],
        ]);
        $this->entityManager->flush();

        $output->writeln(sprintf('Deleted tickets: %d', $deletedTickets));
        $output->writeln(sprintf('Deleted ticket messages: %d', $deletedTicketMessages));
        $output->writeln(sprintf('Deleted important logs: %d', $deletedImportantLogs));
        $output->writeln(sprintf('Deleted non-critical logs: %d', $deletedNonCriticalLogs));
        $output->writeln(sprintf('Deleted logs (total): %d', $deletedLogs));
        $output->writeln(sprintf('Deleted sessions: %d', $deletedSessions));

        return Command::SUCCESS;
    }
}
