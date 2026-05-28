<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\LogIndex;
use App\Repository\AuditLogRepository;
use App\Repository\GdprExportRepository;
use App\Repository\LogIndexRepository;
use App\Repository\RetentionPolicyRepository;
use App\Repository\ScheduledTaskRunRepository;
use App\Repository\TicketMessageRepository;
use App\Repository\TicketRepository;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class PrivacyGdprBackgroundJobService implements PrivacyGdprBackgroundJobRunnerInterface
{
    public function __construct(
        private readonly GdprExportRepository $exportRepository,
        private readonly GdprExportService $exportService,
        private readonly RetentionPolicyRepository $retentionRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly TicketMessageRepository $ticketMessageRepository,
        private readonly LogIndexRepository $logIndexRepository,
        private readonly UserSessionRepository $userSessionRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AppSettingsService $settingsService,
        private readonly ScheduledTaskRunRepository $scheduledTaskRunRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(int $exportProcessLimit = 25, int $exportCleanupLimit = 100, ?\DateTimeImmutable $now = null): PrivacyGdprBackgroundJobResult
    {
        $now ??= new \DateTimeImmutable();

        $counts = [
            'exports_processed' => $this->processPendingExports($exportProcessLimit),
            'exports_deleted' => $this->deleteExpiredExports($now, $exportCleanupLimit),
        ];

        $counts += $this->applyRetentionPolicies($now);
        $counts['temporary_files_deleted'] = $this->deleteTemporaryPrivacyFiles($now);
        $counts += $this->cleanupDatabaseLogs($now);

        $this->entityManager->flush();

        $message = sprintf(
            'Privacy/GDPR jobs completed: processed exports=%d, deleted exports=%d, ticket messages=%d, tickets=%d, logs=%d, sessions=%d, temp files=%d, audit routine logs=%d, scheduler heartbeats=%d.',
            $counts['exports_processed'],
            $counts['exports_deleted'],
            $counts['ticket_messages_deleted'],
            $counts['tickets_deleted'],
            $counts['logs_deleted'],
            $counts['sessions_deleted'],
            $counts['temporary_files_deleted'],
            $counts['audit_routine_logs_deleted'],
            $counts['scheduler_heartbeat_logs_deleted'],
        );

        $this->logger->info('privacy_gdpr.background_jobs_completed', $counts + ['message' => $message]);

        return new PrivacyGdprBackgroundJobResult($counts, $message);
    }


    public function processPendingExportsOnly(int $limit = 25): int
    {
        $processed = $this->processPendingExports($limit);
        $this->entityManager->flush();

        return $processed;
    }

    public function deleteExpiredExportsOnly(int $limit = 100, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $deleted = $this->deleteExpiredExports($now, $limit);
        $this->entityManager->flush();

        return $deleted;
    }

    /** @return array<string,int> */
    public function applyRetentionPoliciesOnly(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $counts = $this->applyRetentionPolicies($now);
        $this->entityManager->flush();

        return $counts;
    }

    private function processPendingExports(int $limit): int
    {
        $pending = $this->exportRepository->claimPending(max(1, $limit));
        foreach ($pending as $export) {
            try {
                $data = $this->exportService->buildExportData($export->getCustomer());
                $export->markReady(
                    $data['fileName'],
                    $data['fileSize'],
                    $data['encryptedPayload'],
                    $data['expiresAt'],
                    $data['readyAt'],
                );
                $this->auditLogger->log(null, 'gdpr.export_ready', [
                    'export_id' => $export->getId(),
                    'user_id' => $export->getCustomer()->getId(),
                    'file_name' => $export->getFileName(),
                ]);
            } catch (\Throwable $exception) {
                $export->markFailed();
                $this->auditLogger->log(null, 'gdpr.export_failed', [
                    'export_id' => $export->getId(),
                    'user_id' => $export->getCustomer()->getId(),
                    'error' => $exception->getMessage(),
                ]);
                $this->logger->error('privacy_gdpr.export_processing_failed', ['export_id' => $export->getId(), 'exception' => $exception]);
            }

            $this->entityManager->persist($export);
        }

        return count($pending);
    }

    private function deleteExpiredExports(\DateTimeImmutable $now, int $limit): int
    {
        $expired = $this->exportRepository->findExpired($now, max(1, $limit));
        foreach ($expired as $export) {
            $this->auditLogger->log(null, 'gdpr.export_deleted', [
                'export_id' => $export->getId(),
                'user_id' => $export->getCustomer()->getId(),
                'expired_at' => $export->getExpiresAt()->format(DATE_RFC3339),
                'deleted_at' => $now->format(DATE_RFC3339),
            ]);
            $this->entityManager->remove($export);
        }

        return count($expired);
    }

    /** @return array<string,int> */
    private function applyRetentionPolicies(\DateTimeImmutable $now): array
    {
        $policy = $this->retentionRepository->getCurrent();

        $ticketDays = $policy?->getTicketRetentionDays() ?? 365;
        $logDays = $policy?->getLogRetentionDays() ?? 7;
        $sessionDays = $policy?->getSessionRetentionDays() ?? 30;
        $importantLogSources = [LogIndex::SOURCE_JOB, LogIndex::SOURCE_MAIL];

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

        return [
            'ticket_messages_deleted' => $deletedTicketMessages,
            'tickets_deleted' => $deletedTickets,
            'important_logs_deleted' => $deletedImportantLogs,
            'non_critical_logs_deleted' => $deletedNonCriticalLogs,
            'logs_deleted' => $deletedLogs,
            'sessions_deleted' => $deletedSessions,
        ];
    }


    /** @return array<string,int> */
    private function cleanupDatabaseLogs(\DateTimeImmutable $now): array
    {
        $routineCutoff = $now->modify(sprintf('-%d days', $this->auditLogRetentionDays()));
        $heartbeatCutoff = $now->modify('-1 day');

        return [
            'audit_routine_logs_deleted' => $this->auditLogRepository->deleteRoutineActionsOlderThan([
                'scheduler.heartbeat',
                'audit_event_instance_query_checked',
                'instance.query.checked',
                'agent.heartbeat',
                'agent.metrics_ingested',
                'agent.metrics_batch_ingested',
                'agent.metrics.batch_recorded',
                'agent.job_completed',
                'agent.job.completed',
                'public_server.status_checked',
            ], $routineCutoff),
            'scheduler_heartbeat_logs_deleted' => $this->scheduledTaskRunRepository->deleteHeartbeatRunsOlderThan($heartbeatCutoff),
        ];
    }

    private function auditLogRetentionDays(): int
    {
        return $this->settingsService->getDatabaseLoggingRoutineRetentionDays();
    }

    private function deleteTemporaryPrivacyFiles(\DateTimeImmutable $now): int
    {
        $deleted = 0;
        $cutoff = $now->modify('-1 day')->getTimestamp();
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gdpr_export_*.zip') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }
            if (@unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
