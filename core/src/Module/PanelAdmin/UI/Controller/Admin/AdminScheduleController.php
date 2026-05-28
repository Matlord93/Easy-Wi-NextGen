<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\Scheduler\CentralSchedulerRunner;
use App\Module\Core\Application\Scheduler\InternalSchedule;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\Scheduler\InternalScheduleProvider;
use App\Module\Core\Application\Scheduler\PrivacyGdprScheduleHandler;
use App\Module\Core\Domain\Entity\BackupSchedule;
use App\Module\Core\Domain\Entity\InstanceSchedule;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\BackupScheduleRepository;
use App\Repository\InstanceScheduleRepository;
use App\Repository\ScheduledTaskRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminScheduleController
{
    public function __construct(
        private readonly InternalScheduleProvider $scheduleProvider,
        private readonly CentralSchedulerRunner $schedulerRunner,
        private readonly ScheduledTaskRunRepository $runRepository,
        private readonly BackupScheduleRepository $backupScheduleRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly AppSettingsService $settingsService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/admin/schedules', name: 'admin_schedules', methods: ['GET'])]
    #[Route(path: '/admin/cronjobs', name: 'admin_cronjobs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);

        $schedules = array_map(fn (InternalSchedule $schedule): array => $this->normalizeSchedule($schedule), $this->scheduleProvider->all());

        return new Response($this->twig->render('admin/schedules/index.html.twig', [
            'activeNav' => 'schedules',
            'summary' => $this->buildSummary($schedules),
            'schedules' => $schedules,
            'runs' => array_map(fn ($run): array => [
                'id' => $run->getId(),
                'schedule_source' => $run->getScheduleSource(),
                'schedule_id' => $run->getScheduleId(),
                'name' => $run->getName(),
                'type' => $run->getType(),
                'module' => $run->getModule(),
                'started_at' => $run->getStartedAt(),
                'finished_at' => $run->getFinishedAt(),
                'status' => $run->getStatus(),
                'message' => $run->getMessage(),
                'created_job_ids' => $run->getCreatedJobIds(),
                'duration_ms' => $run->getDurationMs(),
            ], $this->runRepository->findRecent(100)),
        ]));
    }

    #[Route(path: '/admin/schedules/run-now', name: 'admin_schedules_run_now', methods: ['POST'])]
    public function runNow(Request $request): JsonResponse|RedirectResponse
    {
        $actor = $this->requireAdmin($request);
        $type = (string) ($request->request->get('type') ?? '');
        $source = (string) ($request->request->get('source') ?? '');
        $id = (string) ($request->request->get('id') ?? '');

        if ($type === PrivacyGdprScheduleHandler::TYPE && $actor->getType() !== UserType::Superadmin) {
            throw new AccessDeniedHttpException($this->translator->trans('error_forbidden'));
        }

        $result = $this->schedulerRunner->runNow($type, $source, $id, new \DateTimeImmutable());

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse([
                'status' => $result->status,
                'message' => $result->message,
                'created_job_ids' => $result->createdJobIds,
            ], $result->status === 'failed' ? JsonResponse::HTTP_BAD_REQUEST : JsonResponse::HTTP_OK);
        }

        return new RedirectResponse('/admin/schedules');
    }


    #[Route(path: '/admin/schedules/toggle', name: 'admin_schedules_toggle', methods: ['POST'])]
    public function toggle(Request $request): RedirectResponse
    {
        $this->requireAdmin($request);
        $source = (string) ($request->request->get('source') ?? '');
        $id = (int) ($request->request->get('id') ?? 0);
        $enabled = filter_var($request->request->get('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $enabled ??= false;

        if ($source === 'backup_schedule') {
            $schedule = $this->backupScheduleRepository->find($id);
            if ($schedule instanceof BackupSchedule) {
                $schedule->update($schedule->getCronExpression(), $schedule->getRetentionDays(), $schedule->getRetentionCount(), $enabled, $schedule->getTimeZone(), $schedule->getCompression(), $schedule->isStopBefore());
                $this->entityManager->persist($schedule);
            }
        }

        if ($source === 'system' && (string) ($request->request->get('id') ?? '') === PrivacyGdprScheduleHandler::SCHEDULE_ID) {
            $this->settingsService->setPrivacyGdprJobsEnabled($enabled);
        }

        if ($source === 'instance_schedule') {
            $schedule = $this->instanceScheduleRepository->find($id);
            if ($schedule instanceof InstanceSchedule) {
                $schedule->update($schedule->getAction(), $schedule->getCronExpression(), $schedule->getTimeZone(), $enabled);
                $this->entityManager->persist($schedule);
            }
        }

        $this->entityManager->flush();

        return new RedirectResponse('/admin/schedules');
    }

    #[Route(path: '/admin/schedules/history/{source}/{id}', name: 'admin_schedules_history', methods: ['GET'])]
    public function history(Request $request, string $source, string $id): Response
    {
        $this->requireAdmin($request);

        return new Response($this->twig->render('admin/schedules/history.html.twig', [
            'activeNav' => 'schedules',
            'source' => $source,
            'schedule_id' => $id,
            'runs' => $this->runRepository->findRecentForSchedule($source, $id, 100),
        ]));
    }


    #[Route(path: '/admin/schedules/logs/{source}/{id}', name: 'admin_schedules_logs', methods: ['GET'])]
    public function logs(Request $request, string $source, string $id): Response
    {
        return $this->history($request, $source, $id);
    }

    /** @return array<string,mixed> */
    private function normalizeSchedule(InternalSchedule $schedule): array
    {
        $lastJobId = $schedule->lastJobId;
        if ($lastJobId === null) {
            foreach ($this->runRepository->findRecentForSchedule($schedule->source, $schedule->id, 10) as $run) {
                $jobIds = $run->getCreatedJobIds();
                if ($jobIds !== []) {
                    $lastJobId = (string) $jobIds[0];
                    break;
                }
            }
        }

        return [
            'source' => $schedule->source,
            'id' => $schedule->id,
            'name' => $schedule->name,
            'type' => $schedule->type,
            'module' => $schedule->module,
            'cron_expression' => $schedule->cronExpression,
            'enabled' => $schedule->enabled,
            'payload' => $schedule->payload,
            'last_run_at' => $schedule->lastRunAt,
            'last_queued_at' => $schedule->lastQueuedAt,
            'next_run_at' => $schedule->nextRunAt,
            'last_status' => $schedule->lastStatus,
            'last_error' => $schedule->lastError,
            'last_job_id' => $lastJobId,
            'locked_until' => $schedule->lockedUntil,
            'handler_active' => !str_starts_with($schedule->type, 'unassigned.'),
            'warning' => is_string($schedule->payload['warning'] ?? null) ? (string) $schedule->payload['warning'] : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $schedules
     * @return array<string,mixed>
     */
    private function buildSummary(array $schedules): array
    {
        $now = new \DateTimeImmutable();
        $since = $now->modify('-24 hours');
        $settings = $this->settingsService->getSettings();
        $lastHeartbeat = $this->parseDate($settings[AppSettingsService::KEY_SCHEDULER_LAST_HEARTBEAT_AT] ?? null);
        $schedulerRunning = $lastHeartbeat !== null && $lastHeartbeat >= $now->modify('-5 minutes');
        $due = 0;
        foreach ($schedules as $schedule) {
            if (!($schedule['enabled'] ?? false)) {
                continue;
            }
            $next = $schedule['next_run_at'] ?? null;
            if ($next instanceof \DateTimeImmutable && $next <= $now) {
                $due++;
            }
        }

        return [
            'scheduler_running' => $schedulerRunning,
            'last_heartbeat' => $lastHeartbeat,
            'due_tasks' => $due,
            'failed_24h' => $this->runRepository->countFailedSince($since),
            'created_jobs_24h' => $this->runRepository->countCreatedJobsSince($since),
        ];
    }


    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new UnauthorizedHttpException('session', $this->translator->trans('error_unauthorized'));
        }
        if (!$actor->isAdmin()) {
            throw new AccessDeniedHttpException($this->translator->trans('error_forbidden'));
        }

        return $actor;
    }
}
