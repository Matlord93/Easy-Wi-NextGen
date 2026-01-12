<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Job;
use App\Entity\Ts6Instance;
use App\Enum\ModuleKey;
use App\Enum\Ts6InstanceStatus;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\Ts6InstanceRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\ModuleRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/ts6')]
final class AdminTs6Controller
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly Ts6InstanceRepository $ts6InstanceRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_ts6_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof \App\Entity\User || !in_array($actor->getType(), [UserType::Admin, UserType::Superadmin], true)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->moduleRegistry->isEnabled(ModuleKey::Ts6->value)) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $nodes = $this->agentRepository->findBy([], ['lastSeenAt' => 'DESC']);
        $capabilities = array_map(static function (\App\Entity\Agent $node): array {
            $metadata = $node->getMetadata();
            $supported = is_array($metadata) ? (bool) ($metadata['ts6_supported'] ?? false) : false;

            return [
                'id' => $node->getId(),
                'name' => $node->getName(),
                'status' => $node->getStatus(),
                'ts6_supported' => $supported,
                'last_seen_at' => $node->getLastSeenAt(),
            ];
        }, $nodes);

        $instances = $this->ts6InstanceRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/ts6/index.html.twig', [
            'activeNav' => 'ts6',
            'nodes' => $capabilities,
            'instances' => $this->normalizeInstances($instances),
            'customers' => $this->userRepository->findCustomers(),
            'nodes_for_form' => $nodes,
            'notice' => $this->resolveNoticeKey((string) $request->query->get('notice', '')),
            'virtual_servers_enabled' => $this->moduleRegistry->isEnabled(ModuleKey::TsVirtual->value),
        ]));
    }

    #[Route(path: '/create', name: 'admin_ts6_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof \App\Entity\User || !in_array($actor->getType(), [UserType::Admin, UserType::Superadmin], true)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->moduleRegistry->isEnabled(ModuleKey::Ts6->value)) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $customerId = (int) $request->request->get('customer_id');
        $nodeId = (string) $request->request->get('node_id', '');
        $name = trim((string) $request->request->get('name', ''));

        if ($customerId <= 0 || $nodeId === '' || $name === '') {
            throw new BadRequestHttpException('Customer, node, and name are required.');
        }

        $customer = $this->userRepository->find($customerId);
        if ($customer === null) {
            throw new BadRequestHttpException('Customer not found.');
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            throw new BadRequestHttpException('Node not found.');
        }

        $instance = new Ts6Instance($customer, $node, $name, Ts6InstanceStatus::Provisioning);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $job = $this->queueTs6Job('ts6.instance.create', $instance, [
            'name' => $instance->getName(),
        ]);

        $this->auditLogger->log($actor, 'ts6.instance_created', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return $this->redirectToIndex('admin_ts6_action_queued');
    }

    #[Route(path: '/{id}/action', name: 'admin_ts6_action', methods: ['POST'])]
    public function action(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof \App\Entity\User || !in_array($actor->getType(), [UserType::Admin, UserType::Superadmin], true)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->moduleRegistry->isEnabled(ModuleKey::Ts6->value)) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $instance = $this->ts6InstanceRepository->find($id);
        if ($instance === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $action = strtolower(trim((string) $request->request->get('action', '')));
        $jobType = $this->resolveJobType($action);
        $payload = $this->resolveActionPayload($action, $request);

        $job = $this->queueTs6Job($jobType, $instance, $payload);

        $this->auditLogger->log($actor, sprintf('ts6.instance_%s', $action), [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'action' => $action,
            'job_id' => $job->getId(),
            'payload' => $payload,
        ]);

        $this->entityManager->flush();

        return $this->redirectToIndex('admin_ts6_action_queued');
    }

    private function resolveJobType(string $action): string
    {
        return match ($action) {
            'start' => 'ts6.instance.start',
            'stop' => 'ts6.instance.stop',
            'restart' => 'ts6.instance.restart',
            'update' => 'ts6.instance.update',
            'backup' => 'ts6.instance.backup',
            'restore' => 'ts6.instance.restore',
            default => throw new BadRequestHttpException('Unsupported action.'),
        };
    }

    /**
     * @return array<string, string>
     */
    private function resolveActionPayload(string $action, Request $request): array
    {
        if ($action === 'backup') {
            $backupPath = trim((string) $request->request->get('backup_path', ''));
            return $backupPath === '' ? [] : ['backup_path' => $backupPath];
        }

        if ($action === 'restore') {
            $restorePath = trim((string) $request->request->get('restore_path', ''));
            if ($restorePath === '') {
                throw new BadRequestHttpException('Restore path required.');
            }

            return ['restore_path' => $restorePath];
        }

        return [];
    }

    /**
     * @param Ts6Instance[] $instances
     * @return array<int, array<string, mixed>>
     */
    private function normalizeInstances(array $instances): array
    {
        return array_map(static function (Ts6Instance $instance): array {
            $node = $instance->getNode();
            $customer = $instance->getCustomer();

            return [
                'id' => $instance->getId(),
                'name' => $instance->getName(),
                'status' => $instance->getStatus()->value,
                'status_label' => $instance->getStatus()->name,
                'node' => [
                    'id' => $node->getId(),
                    'name' => $node->getName() ?? $node->getId(),
                ],
                'customer' => [
                    'id' => $customer->getId(),
                    'email' => $customer->getEmail(),
                ],
                'updated_at' => $instance->getUpdatedAt(),
            ];
        }, $instances);
    }

    /**
     * @param array<string, string> $extraPayload
     */
    private function queueTs6Job(string $type, Ts6Instance $instance, array $extraPayload): Job
    {
        $payload = array_merge([
            'ts6_instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'service_name' => sprintf('ts6-%d', $instance->getId() ?? 0),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    private function resolveNoticeKey(string $notice): ?string
    {
        return match ($notice) {
            'admin_ts6_action_queued' => $notice,
            default => null,
        };
    }

    private function redirectToIndex(?string $notice): Response
    {
        $params = [];
        if ($notice !== null) {
            $params['notice'] = $notice;
        }

        $query = $params === [] ? '' : ('?' . http_build_query($params));

        return new Response('', Response::HTTP_FOUND, ['Location' => '/admin/ts6' . $query]);
    }
}
