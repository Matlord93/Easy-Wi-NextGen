<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Ts3Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\Ts3DatabaseMode;
use App\Module\Core\Domain\Enum\Ts3InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\Ts3InstanceRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/ts3/instances')]
final class AdminTs3InstanceController
{
    public function __construct(
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly UserRepository $userRepository,
        private readonly AgentRepository $agentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_ts3_instances', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instances = $this->ts3InstanceRepository->findBy([], ['updatedAt' => 'DESC']);
        $summary = $this->buildSummary($instances);

        return new Response($this->twig->render('admin/ts3/instances/index.html.twig', [
            'instances' => $this->normalizeInstances($instances),
            'summary' => $summary,
            'activeNav' => 'ts3-instances',
        ]));
    }

    #[Route(path: '/new', name: 'admin_ts3_instances_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/ts3/instances/new.html.twig', [
            'activeNav' => 'ts3-instances',
        ]));
    }

    #[Route(path: '/provision', name: 'admin_ts3_instances_provision', methods: ['GET'])]
    public function provision(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/ts3/instances/provision.html.twig', [
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'form' => $this->buildFormContext(),
            'activeNav' => 'ts3-instances',
        ]));
    }

    #[Route(path: '/table', name: 'admin_ts3_instances_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instances = $this->ts3InstanceRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/ts3/instances/_table.html.twig', [
            'instances' => $this->normalizeInstances($instances),
        ]));
    }

    #[Route(path: '/form', name: 'admin_ts3_instances_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/ts3/instances/_form.html.twig', [
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '', name: 'admin_ts3_instances_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $instance = new Ts3Instance(
            $formData['customer'],
            $formData['node'],
            $formData['name'],
            $formData['voice_port'],
            $formData['query_port'],
            $formData['file_port'],
            $formData['db_mode'],
            $formData['db_host'],
            $formData['db_port'],
            $formData['db_name'],
            $formData['db_username'],
            $formData['db_password'],
            Ts3InstanceStatus::Provisioning,
        );

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $job = $this->queueTs3Job('ts3.create', $instance, [
            'name' => $instance->getName(),
            'voice_port' => (string) $instance->getVoicePort(),
            'query_port' => (string) $instance->getQueryPort(),
            'file_port' => (string) $instance->getFilePort(),
            'db_mode' => $instance->getDatabaseMode()->value,
            'db_host' => $instance->getDatabaseHost() ?? '',
            'db_port' => $instance->getDatabasePort() !== null ? (string) $instance->getDatabasePort() : '',
            'db_name' => $instance->getDatabaseName() ?? '',
            'db_username' => $instance->getDatabaseUsername() ?? '',
            'db_password' => $instance->getDatabasePassword() !== null ? json_encode($instance->getDatabasePassword()) : '',
        ]);

        $this->auditLogger->log($actor, 'ts3.instance_created', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'name' => $instance->getName(),
            'voice_port' => $instance->getVoicePort(),
            'query_port' => $instance->getQueryPort(),
            'file_port' => $instance->getFilePort(),
            'db_mode' => $instance->getDatabaseMode()->value,
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/ts3/instances/_form.html.twig', [
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'ts3-instances-changed');

        return $response;
    }

    #[Route(path: '/{id}/action', name: 'admin_ts3_instances_action', methods: ['POST'])]
    public function action(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instance = $this->ts3InstanceRepository->find($id);
        if ($instance === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $action = strtolower(trim((string) $request->request->get('action', '')));
        if ($action === '') {
            return new Response('Action is required.', Response::HTTP_BAD_REQUEST);
        }

        $jobType = $this->actionToJobType($action);
        if ($jobType === null) {
            return new Response('Unsupported action.', Response::HTTP_BAD_REQUEST);
        }

        $extraPayload = $this->buildActionPayload($action, $request);
        if ($extraPayload['errors'] !== []) {
            return new Response(implode(' ', $extraPayload['errors']), Response::HTTP_BAD_REQUEST);
        }

        $job = $this->queueTs3Job($jobType, $instance, $extraPayload['payload']);
        $this->auditLogger->log($actor, sprintf('ts3.instance_%s', $action), [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'action' => $action,
            'job_id' => $job->getId(),
            'payload' => $extraPayload['payload'],
        ]);
        $this->entityManager->flush();

        $instances = $this->ts3InstanceRepository->findBy([], ['updatedAt' => 'DESC']);
        $response = new Response($this->twig->render('admin/ts3/instances/_table.html.twig', [
            'instances' => $this->normalizeInstances($instances),
        ]));
        $response->headers->set('HX-Trigger', 'ts3-instances-changed');

        return $response;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    private function parsePayload(Request $request): array
    {
        $customerId = $request->request->get('customer_id');
        $nodeId = (string) $request->request->get('node_id', '');
        $name = trim((string) $request->request->get('name', ''));
        $voicePortValue = $request->request->get('voice_port');
        $queryPortValue = $request->request->get('query_port');
        $filePortValue = $request->request->get('file_port');
        $dbModeValue = strtolower(trim((string) $request->request->get('db_mode', '')));
        $dbHost = trim((string) $request->request->get('db_host', ''));
        $dbPortValue = $request->request->get('db_port');
        $dbName = trim((string) $request->request->get('db_name', ''));
        $dbUsername = trim((string) $request->request->get('db_username', ''));
        $dbPassword = trim((string) $request->request->get('db_password', ''));
        $errors = [];

        $customer = null;
        if (is_numeric($customerId)) {
            $customer = $this->userRepository->find((int) $customerId);
        }
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            $errors[] = 'Customer is required.';
        }

        $node = $nodeId !== '' ? $this->agentRepository->find($nodeId) : null;
        if ($node === null) {
            $errors[] = 'Node is required.';
        }

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        $voicePort = $this->parsePort($voicePortValue, 'Voice port', $errors);
        $queryPort = $this->parsePort($queryPortValue, 'Query port', $errors);
        $filePort = $this->parsePort($filePortValue, 'File port', $errors);

        $dbMode = Ts3DatabaseMode::tryFrom($dbModeValue);
        if ($dbMode === null) {
            $errors[] = 'DB mode must be sqlite or mysql.';
        }

        $dbPort = null;
        $encryptedPassword = null;
        if ($dbMode === Ts3DatabaseMode::Mysql) {
            if ($dbHost === '' || $dbName === '' || $dbUsername === '') {
                $errors[] = 'MySQL host, name, and username are required.';
            }
            if ($dbPortValue === null || $dbPortValue === '' || !is_numeric($dbPortValue)) {
                $errors[] = 'MySQL port must be numeric.';
            } else {
                $dbPort = (int) $dbPortValue;
                if ($dbPort <= 0 || $dbPort > 65535) {
                    $errors[] = 'MySQL port must be between 1 and 65535.';
                }
            }
            if ($dbPassword === '') {
                $errors[] = 'MySQL password is required.';
            } else {
                $encryptedPassword = $this->encryptionService->encrypt($dbPassword);
            }
        }

        return [
            'customer' => $customer,
            'node' => $node,
            'name' => $name,
            'voice_port' => $voicePort,
            'query_port' => $queryPort,
            'file_port' => $filePort,
            'db_mode' => $dbMode,
            'db_host' => $dbMode === Ts3DatabaseMode::Mysql ? $dbHost : null,
            'db_port' => $dbPort,
            'db_name' => $dbMode === Ts3DatabaseMode::Mysql ? $dbName : null,
            'db_username' => $dbMode === Ts3DatabaseMode::Mysql ? $dbUsername : null,
            'db_password' => $encryptedPassword,
            'errors' => $errors,
        ];
    }

    private function parsePort(mixed $value, string $label, array &$errors): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            $errors[] = sprintf('%s must be numeric.', $label);
            return 0;
        }
        $port = (int) $value;
        if ($port <= 0 || $port > 65535) {
            $errors[] = sprintf('%s must be between 1 and 65535.', $label);
        }
        return $port;
    }

    private function renderFormWithErrors(array $formData, int $status): Response
    {
        $formContext = $this->buildFormContext([
            'customer_id' => $formData['customer']?->getId(),
            'node_id' => $formData['node']?->getId(),
            'name' => $formData['name'],
            'voice_port' => $formData['voice_port'],
            'query_port' => $formData['query_port'],
            'file_port' => $formData['file_port'],
            'db_mode' => $formData['db_mode']?->value ?? '',
            'db_host' => $formData['db_host'],
            'db_port' => $formData['db_port'],
            'db_name' => $formData['db_name'],
            'db_username' => $formData['db_username'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/ts3/instances/_form.html.twig', [
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'form' => $formContext,
        ]), $status);
    }

    private function buildFormContext(?array $override = null): array
    {
        $data = [
            'customer_id' => null,
            'node_id' => null,
            'name' => '',
            'voice_port' => 9987,
            'query_port' => 10011,
            'file_port' => 30033,
            'db_mode' => Ts3DatabaseMode::Sqlite->value,
            'db_host' => '',
            'db_port' => 3306,
            'db_name' => '',
            'db_username' => '',
            'errors' => [],
            'action_url' => '/admin/ts3/instances',
            'submit_label' => 'Provision TS3',
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function actionToJobType(string $action): ?string
    {
        return match ($action) {
            'start' => 'ts3.start',
            'stop' => 'ts3.stop',
            'restart' => 'ts3.restart',
            'update' => 'ts3.update',
            'backup' => 'ts3.backup',
            'restore' => 'ts3.restore',
            'token_reset' => 'ts3.token.reset',
            'slots' => 'ts3.slots.set',
            'logs' => 'ts3.logs.export',
            default => null,
        };
    }

    private function buildActionPayload(string $action, Request $request): array
    {
        $payload = [];
        $errors = [];

        if ($action === 'slots') {
            $slotsValue = $request->request->get('slots');
            if ($slotsValue === null || $slotsValue === '' || !is_numeric($slotsValue)) {
                $errors[] = 'Slots must be numeric.';
            } else {
                $slots = (int) $slotsValue;
                if ($slots <= 0) {
                    $errors[] = 'Slots must be positive.';
                } else {
                    $payload['slots'] = (string) $slots;
                }
            }
        }

        if ($action === 'backup') {
            $backupPath = trim((string) $request->request->get('backup_path', ''));
            if ($backupPath !== '') {
                $payload['backup_path'] = $backupPath;
            }
        }

        if ($action === 'restore') {
            $restorePath = trim((string) $request->request->get('restore_path', ''));
            if ($restorePath === '') {
                $errors[] = 'Restore path is required.';
            } else {
                $payload['restore_path'] = $restorePath;
            }
        }

        return ['payload' => $payload, 'errors' => $errors];
    }

    private function queueTs3Job(string $type, Ts3Instance $instance, array $extraPayload): Job
    {
        $payload = array_merge([
            'ts3_instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'service_name' => sprintf('ts3-%d', $instance->getId() ?? 0),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    /**
     * @param Ts3Instance[] $instances
     */
    private function buildSummary(array $instances): array
    {
        $summary = [
            'total' => count($instances),
            'running' => 0,
            'stopped' => 0,
            'error' => 0,
            'provisioning' => 0,
        ];

        foreach ($instances as $instance) {
            $status = $instance->getStatus();
            if ($status === Ts3InstanceStatus::Running) {
                $summary['running']++;
            } elseif ($status === Ts3InstanceStatus::Stopped) {
                $summary['stopped']++;
            } elseif ($status === Ts3InstanceStatus::Error) {
                $summary['error']++;
            } else {
                $summary['provisioning']++;
            }
        }

        return $summary;
    }

    /**
     * @param Ts3Instance[] $instances
     */
    private function normalizeInstances(array $instances): array
    {
        return array_map(function (Ts3Instance $instance): array {
            return [
                'id' => $instance->getId(),
                'name' => $instance->getName(),
                'customer' => [
                    'id' => $instance->getCustomer()->getId(),
                    'email' => $instance->getCustomer()->getEmail(),
                ],
                'node' => [
                    'id' => $instance->getNode()->getId(),
                    'name' => $instance->getNode()->getName() ?? $instance->getNode()->getId(),
                ],
                'voice_port' => $instance->getVoicePort(),
                'query_port' => $instance->getQueryPort(),
                'file_port' => $instance->getFilePort(),
                'db_mode' => $instance->getDatabaseMode()->value,
                'status' => $instance->getStatus()->value,
                'updatedAt' => $instance->getUpdatedAt(),
            ];
        }, $instances);
    }
}
