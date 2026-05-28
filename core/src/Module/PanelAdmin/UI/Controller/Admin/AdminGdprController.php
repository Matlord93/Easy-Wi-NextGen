<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\GdprDataInventoryMap;
use App\Module\Core\Application\Scheduler\CentralSchedulerRunner;
use App\Module\Core\Application\Scheduler\PrivacyGdprScheduleHandler;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AuditLogRepository;
use App\Repository\RetentionPolicyRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/admin/gdpr')]
final class AdminGdprController
{
    public function __construct(
        private readonly GdprDataInventoryMap $inventoryMap,
        private readonly RetentionPolicyRepository $retentionRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AppSettingsService $settingsService,
        private readonly CentralSchedulerRunner $schedulerRunner,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'admin_gdpr_overview', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);
        $policy = $this->retentionRepository->getCurrent();
        $auditEntries = $this->auditLogRepository->findRecentByActions([
            'gdpr.export_requested',
            'gdpr.export_ready',
            'gdpr.export_failed',
            'gdpr.export_deleted',
            'gdpr.deletion_requested',
            'gdpr.user_anonymized',
        ], 40);

        return new Response($this->twig->render('admin/gdpr/index.html.twig', [
            'activeNav' => 'gdpr-overview',
            'inventory' => $this->inventoryMap->all(),
            'policy' => [
                'ticketRetentionDays' => $policy?->getTicketRetentionDays() ?? 365,
                'logRetentionDays' => $policy?->getLogRetentionDays() ?? 7,
                'sessionRetentionDays' => $policy?->getSessionRetentionDays() ?? 30,
            ],
            'auditEntries' => $auditEntries,
            'privacyGdprSchedule' => $this->buildPrivacyGdprSchedule(),
            'isSuperadmin' => $this->requireAdmin($request)->getType() === UserType::Superadmin,
        ]));
    }


    #[Route(path: '/background-jobs/toggle', name: 'admin_gdpr_background_jobs_toggle', methods: ['POST'])]
    public function toggleBackgroundJobs(Request $request): RedirectResponse
    {
        $this->requireAdmin($request);
        $enabled = filter_var($request->request->get('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        $this->settingsService->setPrivacyGdprJobsEnabled($enabled);

        return new RedirectResponse('/admin/gdpr?saved=privacy-gdpr-jobs');
    }

    #[Route(path: '/background-jobs/run-now', name: 'admin_gdpr_background_jobs_run_now', methods: ['POST'])]
    public function runBackgroundJobsNow(Request $request): RedirectResponse
    {
        $actor = $this->requireAdmin($request);
        if ($actor->getType() !== UserType::Superadmin) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException($this->translator->trans('error_forbidden'));
        }

        $this->schedulerRunner->runNow(PrivacyGdprScheduleHandler::TYPE, 'system', PrivacyGdprScheduleHandler::SCHEDULE_ID, new \DateTimeImmutable());

        return new RedirectResponse('/admin/gdpr?ran=privacy-gdpr-jobs');
    }

    /** @return array<string,mixed> */
    private function buildPrivacyGdprSchedule(): array
    {
        $settings = $this->settingsService->getSettings();
        $lastRunAt = null;
        $lastRunValue = $settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_RUN_AT] ?? null;
        if (is_string($lastRunValue) && $lastRunValue !== '') {
            try {
                $lastRunAt = new \DateTimeImmutable($lastRunValue);
            } catch (\Throwable) {
                $lastRunAt = null;
            }
        }

        $nextRunAt = null;
        $interval = $this->settingsService->getPrivacyGdprJobsInterval();
        if ($this->settingsService->isPrivacyGdprJobsEnabled() && \Cron\CronExpression::isValidExpression($interval)) {
            try {
                $next = \Cron\CronExpression::factory($interval)->getNextRunDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 0, true);
                $nextRunAt = \DateTimeImmutable::createFromMutable($next)->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                $nextRunAt = null;
            }
        }

        return [
            'enabled' => $this->settingsService->isPrivacyGdprJobsEnabled(),
            'interval' => $interval,
            'last_run_at' => $lastRunAt,
            'next_run_at' => $nextRunAt,
            'last_status' => is_string($settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_STATUS] ?? null) ? $settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_STATUS] : null,
            'last_error' => is_string($settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_ERROR] ?? null) ? $settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_ERROR] : null,
        ];
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException($this->translator->trans('error_forbidden'));
        }

        return $actor;
    }
}
