<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Instance;
use App\Entity\InstanceSchedule;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\InstanceScheduleAction;
use App\Enum\InstanceUpdatePolicy;
use App\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Service\AuditLogger;
use App\Service\InstanceJobPayloadBuilder;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/instances')]
final class CustomerInstanceController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly InstanceJobPayloadBuilder $instanceJobPayloadBuilder,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_instances', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->instanceRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/instances/index.html.twig', [
            'instances' => $this->normalizeInstances($instances),
            'activeNav' => 'instances',
        ]));
    }

    #[Route(path: '/{id}/update', name: 'customer_instance_update', methods: ['POST'])]
    public function updateInstance(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $template = $instance->getTemplate();

        if ($template->getSniperProfile() === null && $template->getUpdateCommand() === '') {
            return $this->renderInstanceCard($instance, null, 'No update command configured for this template.');
        }

        $targetBuildId = $instance->getLockedBuildId();
        $targetVersion = $instance->getLockedVersion();

        $job = new Job('sniper.update', $this->instanceJobPayloadBuilder->buildSniperUpdatePayload($instance, $targetBuildId, $targetVersion));
        $this->entityManager->persist($job);
        $instance->setLastUpdateQueuedAt(new \DateTimeImmutable());
        $this->entityManager->persist($instance);

        $this->auditLogger->log($customer, 'instance.update.queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'job_id' => $job->getId(),
            'locked_build_id' => $targetBuildId,
            'locked_version' => $targetVersion,
            'source' => 'customer.manual',
        ]);

        $this->entityManager->flush();

        return $this->renderInstanceCard($instance, 'Update queued.', null);
    }

    #[Route(path: '/{id}/settings', name: 'customer_instance_settings', methods: ['POST'])]
    public function updateSettings(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        $policyRaw = (string) $request->request->get('update_policy', InstanceUpdatePolicy::Manual->value);
        $lockedBuildId = trim((string) $request->request->get('locked_build_id', ''));
        $lockedVersion = trim((string) $request->request->get('locked_version', ''));
        $cronExpression = trim((string) $request->request->get('cron_expression', ''));
        $timeZone = trim((string) $request->request->get('time_zone', 'UTC'));

        $policy = InstanceUpdatePolicy::tryFrom($policyRaw);
        if ($policy === null) {
            throw new BadRequestHttpException('Invalid update policy.');
        }

        if ($policy === InstanceUpdatePolicy::Auto && $cronExpression === '') {
            return $this->renderInstanceCard($instance, null, 'Auto updates require a cron schedule.');
        }

        if ($policy === InstanceUpdatePolicy::Auto && !CronExpression::isValidExpression($cronExpression)) {
            return $this->renderInstanceCard($instance, null, 'Cron expression is invalid.');
        }

        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Exception) {
            return $this->renderInstanceCard($instance, null, 'Time zone is invalid.');
        }

        $instance->setUpdatePolicy($policy);
        $instance->setLockedBuildId($lockedBuildId !== '' ? $lockedBuildId : null);
        $instance->setLockedVersion($lockedVersion !== '' ? $lockedVersion : null);
        $this->entityManager->persist($instance);

        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Update);
        if ($policy === InstanceUpdatePolicy::Auto) {
            if ($schedule === null) {
                $schedule = new InstanceSchedule(
                    $instance,
                    $customer,
                    InstanceScheduleAction::Update,
                    $cronExpression,
                    $timeZone,
                    true,
                );
            } else {
                $schedule->update(InstanceScheduleAction::Update, $cronExpression, $timeZone, true);
            }
            $this->entityManager->persist($schedule);
        } elseif ($schedule !== null) {
            $schedule->update(InstanceScheduleAction::Update, $schedule->getCronExpression(), $schedule->getTimeZone(), false);
            $this->entityManager->persist($schedule);
        }

        $this->auditLogger->log($customer, 'instance.update.settings_updated', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'policy' => $policy->value,
            'locked_build_id' => $instance->getLockedBuildId(),
            'locked_version' => $instance->getLockedVersion(),
            'cron_expression' => $schedule?->getCronExpression(),
            'time_zone' => $schedule?->getTimeZone(),
            'schedule_enabled' => $schedule?->isEnabled(),
        ]);

        $this->entityManager->flush();

        return $this->renderInstanceCard($instance, 'Update settings saved.', null);
    }

    #[Route(path: '/{id}/rollback', name: 'customer_instance_rollback', methods: ['POST'])]
    public function rollback(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if ($instance->getPreviousBuildId() === null && $instance->getPreviousVersion() === null) {
            return $this->renderInstanceCard($instance, null, 'No previous build available for rollback.');
        }

        $job = new Job('sniper.update', $this->instanceJobPayloadBuilder->buildSniperUpdatePayload(
            $instance,
            $instance->getPreviousBuildId(),
            $instance->getPreviousVersion(),
        ));
        $this->entityManager->persist($job);
        $instance->setLastUpdateQueuedAt(new \DateTimeImmutable());
        $this->entityManager->persist($instance);

        $this->auditLogger->log($customer, 'instance.update.rollback_queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'job_id' => $job->getId(),
            'rollback_build_id' => $instance->getPreviousBuildId(),
            'rollback_version' => $instance->getPreviousVersion(),
        ]);

        $this->entityManager->flush();

        return $this->renderInstanceCard($instance, 'Rollback queued.', null);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findCustomerInstance(User $customer, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    /**
     * @param Instance[] $instances
     */
    private function normalizeInstances(array $instances): array
    {
        $schedules = $this->instanceScheduleRepository->findByInstancesAndAction($instances, InstanceScheduleAction::Update);
        $scheduleIndex = [];
        foreach ($schedules as $schedule) {
            $scheduleIndex[$schedule->getInstance()->getId()] = $schedule;
        }

        return array_map(function (Instance $instance) use ($scheduleIndex): array {
            $schedule = $scheduleIndex[$instance->getId()] ?? null;

            return $this->normalizeInstance($instance, $schedule);
        }, $instances);
    }

    private function normalizeInstance(Instance $instance, ?InstanceSchedule $schedule, ?string $notice = null, ?string $error = null): array
    {
        return [
            'id' => $instance->getId(),
            'template' => [
                'name' => $instance->getTemplate()->getDisplayName(),
                'game_key' => $instance->getTemplate()->getGameKey(),
            ],
            'node' => [
                'id' => $instance->getNode()->getId(),
                'name' => $instance->getNode()->getName(),
            ],
            'status' => $instance->getStatus()->value,
            'update_policy' => $instance->getUpdatePolicy()->value,
            'current_build_id' => $instance->getCurrentBuildId(),
            'current_version' => $instance->getCurrentVersion(),
            'previous_build_id' => $instance->getPreviousBuildId(),
            'previous_version' => $instance->getPreviousVersion(),
            'locked_build_id' => $instance->getLockedBuildId(),
            'locked_version' => $instance->getLockedVersion(),
            'last_update_queued_at' => $instance->getLastUpdateQueuedAt(),
            'schedule' => $schedule === null ? null : [
                'cron_expression' => $schedule->getCronExpression(),
                'time_zone' => $schedule->getTimeZone() ?? 'UTC',
                'enabled' => $schedule->isEnabled(),
            ],
            'notice' => $notice,
            'error' => $error,
        ];
    }

    private function renderInstanceCard(Instance $instance, ?string $notice, ?string $error): Response
    {
        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Update);

        return new Response($this->twig->render('customer/instances/_card.html.twig', [
            'instance' => $this->normalizeInstance($instance, $schedule, $notice, $error),
        ]));
    }
}
