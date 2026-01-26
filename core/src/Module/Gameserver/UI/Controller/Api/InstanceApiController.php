<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Api;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Module\Ports\Application\PortLeaseManager;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\TemplateRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\DiskEnforcementService;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InstanceApiController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TemplateRepository $templateRepository,
        private readonly AgentRepository $agentRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly PortLeaseManager $portLeaseManager,
        private readonly AuditLogger $auditLogger,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly AppSettingsService $appSettingsService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/admin/instances', name: 'admin_instances_create_api', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/instances', name: 'admin_instances_create_api_v1', methods: ['POST'])]
    public function createInstance(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $request->toArray();
        $customerId = $payload['customer_id'] ?? null;
        $templateId = $payload['template_id'] ?? null;
        $nodeId = (string) ($payload['node_id'] ?? '');
        $cpuLimitValue = $payload['cpu_limit'] ?? null;
        $ramLimitValue = $payload['ram_limit'] ?? null;
        $diskLimitValue = $payload['disk_limit'] ?? null;
        $portBlockId = $payload['port_block_id'] ?? null;
        $maxSlotsValue = $payload['max_slots'] ?? null;
        $currentSlotsValue = $payload['current_slots'] ?? null;

        if ($customerId === null || $templateId === null || $nodeId === '' || $cpuLimitValue === null || $ramLimitValue === null || $diskLimitValue === null) {
            return new JsonResponse(['error' => 'Missing required fields.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_numeric($cpuLimitValue) || !is_numeric($ramLimitValue) || !is_numeric($diskLimitValue)) {
            return new JsonResponse(['error' => 'Limits must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $cpuLimit = (int) $cpuLimitValue;
        $ramLimit = (int) $ramLimitValue;
        $diskLimit = (int) $diskLimitValue;

        if ($cpuLimit <= 0 || $ramLimit <= 0 || $diskLimit <= 0) {
            return new JsonResponse(['error' => 'Limits must be positive.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $minSlots = $this->appSettingsService->getGameserverMinSlots();
        $maxSlotsLimit = $this->appSettingsService->getGameserverMaxSlots();
        $defaultSlots = $this->appSettingsService->getGameserverDefaultSlots();
        $defaultSlots = max($minSlots, min($defaultSlots, $maxSlotsLimit));

        $maxSlots = $maxSlotsLimit;
        if ($maxSlotsValue !== null && $maxSlotsValue !== '') {
            if (!is_numeric($maxSlotsValue)) {
                return new JsonResponse(['error' => 'Max slots must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
            }
            $maxSlots = (int) $maxSlotsValue;
        }

        $currentSlots = $defaultSlots;
        if ($currentSlotsValue !== null && $currentSlotsValue !== '') {
            if (!is_numeric($currentSlotsValue)) {
                return new JsonResponse(['error' => 'Current slots must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
            }
            $currentSlots = (int) $currentSlotsValue;
        }

        if ($maxSlots < $minSlots) {
            return new JsonResponse(['error' => 'Max slots must be greater than or equal to the minimum slots.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($maxSlots > $maxSlotsLimit) {
            return new JsonResponse(['error' => 'Max slots exceeds the allowed maximum.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($currentSlots < $minSlots || $currentSlots > $maxSlots) {
            return new JsonResponse(['error' => 'Current slots must be within the allowed range.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $customer = $this->userRepository->find($customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            return new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $template = $this->templateRepository->find($templateId);
        if ($template === null) {
            return new JsonResponse(['error' => 'Template not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return new JsonResponse(['error' => 'Node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $blockMessage = $this->diskEnforcementService->guardNodeProvisioning($node, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $portBlock = null;
        if ($portBlockId !== null && $portBlockId !== '') {
            $portBlock = $this->portBlockRepository->find((string) $portBlockId);
            if ($portBlock === null) {
                return new JsonResponse(['error' => 'Port block not found.'], JsonResponse::HTTP_NOT_FOUND);
            }
            if ($portBlock->getCustomer()->getId() !== $customer->getId()) {
                return new JsonResponse(['error' => 'Port block does not belong to customer.'], JsonResponse::HTTP_FORBIDDEN);
            }
            if ($portBlock->getInstance() !== null) {
                return new JsonResponse(['error' => 'Port block is already assigned.'], JsonResponse::HTTP_CONFLICT);
            }
            if ($portBlock->getPool()->getNode()->getId() !== $node->getId()) {
                return new JsonResponse(['error' => 'Port block does not belong to selected node.'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $requiredPorts = $template->getRequiredPorts();
        $requiredCount = count($requiredPorts);

        if ($portBlock === null && $requiredCount > 0) {
            $portBlock = $this->allocatePortBlock($node, $customer, $requiredCount);
            if ($portBlock === null) {
                return new JsonResponse(['error' => 'No free port blocks available on the selected node.'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $instance = new Instance(
            $customer,
            $template,
            $node,
            $cpuLimit,
            $ramLimit,
            $diskLimit,
            $portBlock?->getId(),
            InstanceStatus::PendingSetup,
            InstanceUpdatePolicy::Manual,
        );

        $instance->setSlots($currentSlots);
        $instance->setMaxSlots($maxSlots);
        $instance->setCurrentSlots($currentSlots);

        $this->entityManager->persist($instance);
        if ($portBlock !== null) {
            $this->entityManager->persist($portBlock);
        }
        $this->entityManager->flush();

        if ($portBlock !== null) {
            $portBlock->assignInstance($instance);
            $this->entityManager->persist($portBlock);
            $this->auditLogger->log($actor, 'port_block.assigned', [
                'port_block_id' => $portBlock->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $customer->getId(),
            ]);
        }

        $firewallJob = null;
        if ($portBlock !== null) {
            $ports = $portBlock->getPorts();
            if ($ports !== []) {
                $firewallJob = new Job('firewall.open_ports', [
                    'agent_id' => $node->getId(),
                    'instance_id' => (string) $instance->getId(),
                    'port_block_id' => $portBlock->getId(),
                    'ports' => implode(',', array_map('strval', $ports)),
                ]);
                $this->entityManager->persist($firewallJob);
            }
        }

        $this->auditLogger->log($actor, 'instance.created', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'template_id' => $template->getId(),
            'node_id' => $node->getId(),
            'cpu_limit' => $cpuLimit,
            'ram_limit' => $ramLimit,
            'disk_limit' => $diskLimit,
            'port_block_id' => $instance->getPortBlockId(),
            'firewall_job_id' => $firewallJob?->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'template_id' => $template->getId(),
            'node_id' => $node->getId(),
            'cpu_limit' => $instance->getCpuLimit(),
            'ram_limit' => $instance->getRamLimit(),
            'disk_limit' => $instance->getDiskLimit(),
            'port_block_id' => $instance->getPortBlockId(),
            'status' => $instance->getStatus()->value,
        ], JsonResponse::HTTP_CREATED);
    }

    private function allocatePortBlock(Agent $node, User $customer, int $requiredCount): ?PortBlock
    {
        if ($requiredCount <= 0) {
            return null;
        }

        $pools = $this->portPoolRepository->findBy(['node' => $node]);
        foreach ($pools as $pool) {
            try {
                return $this->portLeaseManager->allocateBlock($pool, $customer, $requiredCount);
            } catch (\RuntimeException) {
                continue;
            }
        }

        return null;
    }

    #[Route(path: '/api/admin/instances/{id}', name: 'admin_instances_delete', methods: ['DELETE'])]
    #[Route(path: '/api/v1/admin/instances/{id}', name: 'admin_instances_delete_v1', methods: ['DELETE'])]
    public function deleteInstance(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            return new JsonResponse(['error' => 'Instance not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $portBlock = null;
        if ($instance->getPortBlockId() !== null) {
            $portBlock = $this->portBlockRepository->find($instance->getPortBlockId());
            if ($portBlock !== null && $portBlock->getInstance()?->getId() === $instance->getId()) {
                $portBlock->releaseInstance();
                $this->entityManager->persist($portBlock);
                $this->auditLogger->log($actor, 'port_block.released', [
                    'port_block_id' => $portBlock->getId(),
                    'instance_id' => $instance->getId(),
                    'customer_id' => $instance->getCustomer()->getId(),
                ]);
            }
        }

        $this->entityManager->remove($instance);

        $job = null;
        if ($portBlock !== null) {
            $ports = $portBlock->getPorts();
            if ($ports !== []) {
                $job = new Job('firewall.close_ports', [
                    'agent_id' => $instance->getNode()->getId(),
                    'instance_id' => (string) $instance->getId(),
                    'port_block_id' => $portBlock->getId(),
                    'ports' => implode(',', array_map('strval', $ports)),
                ]);
                $this->entityManager->persist($job);
            }
        }

        $this->auditLogger->log($actor, 'instance.deleted', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'template_id' => $instance->getTemplate()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'port_block_id' => $instance->getPortBlockId(),
            'firewall_job_id' => $job?->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse(['status' => 'deleted']);
    }

    #[Route(path: '/api/admin/instances/{id}/update-settings', name: 'admin_instances_update_settings', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/instances/{id}/update-settings', name: 'admin_instances_update_settings_v1', methods: ['POST'])]
    public function updateInstanceSettings(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            return new JsonResponse(['error' => 'Instance not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();
        $policyRaw = (string) ($payload['update_policy'] ?? InstanceUpdatePolicy::Manual->value);
        $lockedBuildId = trim((string) ($payload['locked_build_id'] ?? ''));
        $lockedVersion = trim((string) ($payload['locked_version'] ?? ''));
        $cronExpression = trim((string) ($payload['cron_expression'] ?? ''));
        $timeZone = trim((string) ($payload['time_zone'] ?? 'UTC'));

        $policy = InstanceUpdatePolicy::tryFrom($policyRaw);
        if ($policy === null) {
            return new JsonResponse(['error' => 'Invalid update policy.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($policy === InstanceUpdatePolicy::Auto && $cronExpression === '') {
            return new JsonResponse(['error' => 'Auto updates require a cron schedule.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($policy === InstanceUpdatePolicy::Auto && !CronExpression::isValidExpression($cronExpression)) {
            return new JsonResponse(['error' => 'Cron expression is invalid.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $timeZone = $timeZone === '' ? 'UTC' : $timeZone;
        try {
            new \DateTimeZone($timeZone);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Time zone is invalid.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $instance->setUpdatePolicy($policy);
        $instance->setLockedBuildId($lockedBuildId !== '' ? $lockedBuildId : null);
        $instance->setLockedVersion($lockedVersion !== '' ? $lockedVersion : null);
        $this->entityManager->persist($instance);

        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, \App\Module\Core\Domain\Enum\InstanceScheduleAction::Update);
        if ($policy === InstanceUpdatePolicy::Auto) {
            if ($schedule === null) {
                $schedule = new \App\Module\Core\Domain\Entity\InstanceSchedule(
                    $instance,
                    $instance->getCustomer(),
                    \App\Module\Core\Domain\Enum\InstanceScheduleAction::Update,
                    $cronExpression,
                    $timeZone,
                    true,
                );
            } else {
                $schedule->update(\App\Module\Core\Domain\Enum\InstanceScheduleAction::Update, $cronExpression, $timeZone, true);
            }
            $this->entityManager->persist($schedule);
        } elseif ($schedule !== null) {
            $schedule->update(\App\Module\Core\Domain\Enum\InstanceScheduleAction::Update, $schedule->getCronExpression(), $schedule->getTimeZone(), false);
            $this->entityManager->persist($schedule);
        }

        $this->auditLogger->log($actor, 'instance.update.settings_overridden', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'policy' => $policy->value,
            'locked_build_id' => $instance->getLockedBuildId(),
            'locked_version' => $instance->getLockedVersion(),
            'cron_expression' => $schedule?->getCronExpression(),
            'time_zone' => $schedule?->getTimeZone(),
            'schedule_enabled' => $schedule?->isEnabled(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'updated',
            'instance_id' => $instance->getId(),
            'policy' => $instance->getUpdatePolicy()->value,
            'locked_build_id' => $instance->getLockedBuildId(),
            'locked_version' => $instance->getLockedVersion(),
            'cron_expression' => $schedule?->getCronExpression(),
            'time_zone' => $schedule?->getTimeZone(),
            'schedule_enabled' => $schedule?->isEnabled(),
        ]);
    }

    #[Route(path: '/api/instances', name: 'customer_instances_api', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances', name: 'customer_instances_api_v1', methods: ['GET'])]
    public function listInstances(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $instances = $this->instanceRepository->findByCustomer($actor);
        $payload = [];

        foreach ($instances as $instance) {
            $node = $instance->getNode();
            $template = $instance->getTemplate();
            $payload[] = [
                'id' => $instance->getId(),
                'template' => [
                    'id' => $template->getId(),
                    'name' => $template->getDisplayName(),
                    'game_key' => $template->getGameKey(),
                ],
                'node' => [
                    'id' => $node->getId(),
                    'name' => $node->getName(),
                ],
                'cpu_limit' => $instance->getCpuLimit(),
                'ram_limit' => $instance->getRamLimit(),
                'disk_limit' => $instance->getDiskLimit(),
                'port_block_id' => $instance->getPortBlockId(),
                'status' => $instance->getStatus()->value,
            ];
        }

        return new JsonResponse(['instances' => $payload]);
    }

}
