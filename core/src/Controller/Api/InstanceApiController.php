<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Instance;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\InstanceStatus;
use App\Enum\InstanceUpdatePolicy;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\TemplateRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\DiskEnforcementService;
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
        private readonly AuditLogger $auditLogger,
        private readonly DiskEnforcementService $diskEnforcementService,
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

        $this->entityManager->persist($instance);
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

        $this->auditLogger->log($actor, 'instance.created', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'template_id' => $template->getId(),
            'node_id' => $node->getId(),
            'cpu_limit' => $cpuLimit,
            'ram_limit' => $ramLimit,
            'disk_limit' => $diskLimit,
            'port_block_id' => $instance->getPortBlockId(),
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

        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, \App\Enum\InstanceScheduleAction::Update);
        if ($policy === InstanceUpdatePolicy::Auto) {
            if ($schedule === null) {
                $schedule = new \App\Entity\InstanceSchedule(
                    $instance,
                    $instance->getCustomer(),
                    \App\Enum\InstanceScheduleAction::Update,
                    $cronExpression,
                    $timeZone,
                    true,
                );
            } else {
                $schedule->update(\App\Enum\InstanceScheduleAction::Update, $cronExpression, $timeZone, true);
            }
            $this->entityManager->persist($schedule);
        } elseif ($schedule !== null) {
            $schedule->update(\App\Enum\InstanceScheduleAction::Update, $schedule->getCronExpression(), $schedule->getTimeZone(), false);
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
