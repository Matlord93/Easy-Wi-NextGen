<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceSftpCredentialRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\TemplateRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\DiskUsageFormatter;
use App\Module\Core\Application\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/instances')]
final class AdminInstanceController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly UserRepository $userRepository,
        private readonly TemplateRepository $templateRepository,
        private readonly AgentRepository $agentRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly DiskUsageFormatter $diskUsageFormatter,
        private readonly EncryptionService $encryptionService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_instances', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instances = $this->instanceRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/instances/index.html.twig', [
            'instances' => $this->normalizeInstances($instances, $this->buildSftpCredentialMap($instances)),
            'summary' => $this->buildSummary($instances),
            'activeNav' => 'game-instances',
        ]));
    }

    #[Route(path: '/new', name: 'admin_instances_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/instances/new.html.twig', [
            'activeNav' => 'game-instances',
        ]));
    }

    #[Route(path: '/provision', name: 'admin_instances_provision', methods: ['GET'])]
    public function provision(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/instances/provision.html.twig', [
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'templates' => $this->templateRepository->findBy([], ['displayName' => 'ASC']),
            'form' => $this->buildFormContext(),
            'activeNav' => 'game-instances',
        ]));
    }

    #[Route(path: '/table', name: 'admin_instances_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instances = $this->instanceRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/instances/_table.html.twig', [
            'instances' => $this->normalizeInstances($instances, $this->buildSftpCredentialMap($instances)),
        ]));
    }

    #[Route(path: '/form', name: 'admin_instances_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/instances/_form.html.twig', [
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'templates' => $this->templateRepository->findBy([], ['displayName' => 'ASC']),
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '', name: 'admin_instances_create', methods: ['POST'])]
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

        $blockMessage = $this->diskEnforcementService->guardNodeProvisioning($formData['node'], new \DateTimeImmutable());
        if ($blockMessage !== null) {
            $formData['errors'][] = $blockMessage;
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $instance = new Instance(
            $formData['customer'],
            $formData['template'],
            $formData['node'],
            $formData['cpu_limit'],
            $formData['ram_limit'],
            $formData['disk_limit'],
            $formData['port_block']?->getId(),
            InstanceStatus::PendingSetup,
            InstanceUpdatePolicy::Manual,
        );

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        if ($formData['port_block'] !== null) {
            $formData['port_block']->assignInstance($instance);
            $this->entityManager->persist($formData['port_block']);
            $this->auditLogger->log($actor, 'port_block.assigned', [
                'port_block_id' => $formData['port_block']->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $formData['customer']->getId(),
            ]);
        }

        $this->auditLogger->log($actor, 'instance.created', [
            'instance_id' => $instance->getId(),
            'customer_id' => $formData['customer']->getId(),
            'template_id' => $formData['template']->getId(),
            'node_id' => $formData['node']->getId(),
            'cpu_limit' => $formData['cpu_limit'],
            'ram_limit' => $formData['ram_limit'],
            'disk_limit' => $formData['disk_limit'],
            'port_block_id' => $instance->getPortBlockId(),
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/instances/_form.html.twig', [
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'templates' => $this->templateRepository->findBy([], ['displayName' => 'ASC']),
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'instances-changed');

        return $response;
    }

    #[Route(path: '/customers', name: 'admin_instances_customers_create', methods: ['POST'])]
    public function createCustomer(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parseCustomerPayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderCustomerFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $customer = new User($formData['email'], UserType::Customer);
        $customer->setPasswordHash($this->passwordHasher->hashPassword($customer, $formData['password']));

        $this->entityManager->persist($customer);
        $preferences = new InvoicePreferences($customer, 'de_DE', true, true, 'manual', 'de');
        $this->entityManager->persist($preferences);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'user.created', [
            'user_id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'type' => $customer->getType()->value,
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/instances/_customer_form.html.twig', [
            'customerForm' => $this->buildCustomerFormContext([
                'success' => sprintf('Customer %s created.', $customer->getEmail()),
            ]),
        ]));
        $response->headers->set('HX-Trigger', 'instances-form-refresh');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_instances_delete_form', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
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

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'instances-changed');

        return $response;
    }

    #[Route(path: '/{id}/sftp/provision', name: 'admin_instances_sftp_provision', methods: ['POST'])]
    public function provisionSftp(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
        if ($credential === null) {
            $username = $this->buildSftpUsername($instance);
            $password = $this->generateSftpPassword();
            $encryptedPassword = $this->encryptionService->encrypt($password);

            $credential = new InstanceSftpCredential($instance, $username, $encryptedPassword);
            $this->entityManager->persist($credential);

            $job = new Job('instance.sftp.credentials.reset', [
                'instance_id' => (string) $instance->getId(),
                'customer_id' => (string) $instance->getCustomer()->getId(),
                'agent_id' => $instance->getNode()->getId(),
                'username' => $username,
                'password' => $password,
            ]);
            $this->entityManager->persist($job);

            $this->auditLogger->log($actor, 'instance.sftp.credentials.reset_requested', [
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'job_id' => $job->getId(),
                'username' => $username,
            ]);
            $this->entityManager->flush();
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'instances-changed');

        return $response;
    }

    private function parsePayload(Request $request): array
    {
        $errors = [];

        $customerId = $request->request->get('customer_id');
        $templateId = $request->request->get('template_id');
        $nodeId = (string) $request->request->get('node_id', '');
        $cpuLimitValue = $request->request->get('cpu_limit');
        $ramLimitValue = $request->request->get('ram_limit');
        $diskLimitValue = $request->request->get('disk_limit');
        $portBlockId = $request->request->get('port_block_id');

        if ($customerId === null || $templateId === null || $nodeId === '' || $cpuLimitValue === null || $ramLimitValue === null || $diskLimitValue === null) {
            $errors[] = 'Customer, template, node, and resource limits are required.';
        }

        if (!is_numeric($cpuLimitValue) || !is_numeric($ramLimitValue) || !is_numeric($diskLimitValue)) {
            $errors[] = 'CPU, RAM, and disk limits must be numeric.';
        }

        $cpuLimit = is_numeric($cpuLimitValue) ? (int) $cpuLimitValue : 0;
        $ramLimit = is_numeric($ramLimitValue) ? (int) $ramLimitValue : 0;
        $diskLimit = is_numeric($diskLimitValue) ? (int) $diskLimitValue : 0;

        if ($cpuLimit <= 0 || $ramLimit <= 0 || $diskLimit <= 0) {
            $errors[] = 'CPU, RAM, and disk limits must be positive.';
        }

        $customer = $customerId !== null ? $this->userRepository->find($customerId) : null;
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            $errors[] = 'Customer not found.';
        }

        $template = $templateId !== null ? $this->templateRepository->find($templateId) : null;
        if ($template === null) {
            $errors[] = 'Template not found.';
        }

        $node = $nodeId !== '' ? $this->agentRepository->find($nodeId) : null;
        if ($node === null) {
            $errors[] = 'Node not found.';
        }

        $portBlock = null;
        if ($portBlockId !== null && $portBlockId !== '') {
            $portBlock = $this->portBlockRepository->find((string) $portBlockId);
            if ($portBlock === null) {
                $errors[] = 'Port block not found.';
            } elseif ($customer !== null && $portBlock->getCustomer()->getId() !== $customer->getId()) {
                $errors[] = 'Port block does not belong to customer.';
            } elseif ($portBlock->getInstance() !== null) {
                $errors[] = 'Port block is already assigned.';
            } elseif ($node !== null && $portBlock->getPool()->getNode()->getId() !== $node->getId()) {
                $errors[] = 'Port block does not belong to selected node.';
            }
        }

        return [
            'errors' => $errors,
            'customer' => $customer,
            'template' => $template,
            'node' => $node,
            'cpu_limit' => $cpuLimit,
            'ram_limit' => $ramLimit,
            'disk_limit' => $diskLimit,
            'port_block' => $portBlock,
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'node_id' => $nodeId,
            'port_block_id' => $portBlockId ?? '',
        ];
    }

    private function buildFormContext(?array $override = null): array
    {
        $data = [
            'customer_id' => '',
            'template_id' => '',
            'node_id' => '',
            'cpu_limit' => 2,
            'ram_limit' => 4096,
            'disk_limit' => 20000,
            'port_block_id' => '',
            'errors' => [],
            'action_url' => '/admin/instances',
            'submit_label' => 'admin_instances_submit',
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $formData, int $status): Response
    {
        return new Response($this->twig->render('admin/instances/_form.html.twig', [
            'customers' => $this->userRepository->findCustomers(),
            'nodes' => $this->agentRepository->findBy([], ['name' => 'ASC']),
            'templates' => $this->templateRepository->findBy([], ['displayName' => 'ASC']),
            'form' => $this->buildFormContext([
                'customer_id' => $formData['customer_id'],
                'template_id' => $formData['template_id'],
                'node_id' => $formData['node_id'],
                'cpu_limit' => $formData['cpu_limit'],
                'ram_limit' => $formData['ram_limit'],
                'disk_limit' => $formData['disk_limit'],
                'port_block_id' => $formData['port_block_id'],
                'errors' => $formData['errors'],
            ]),
        ]), $status);
    }

    private function parseCustomerPayload(Request $request): array
    {
        $errors = [];
        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');

        if ($email === '' || $password === '') {
            $errors[] = 'Email and password are required.';
        }

        if ($email !== '' && $this->userRepository->findOneByEmail($email) !== null) {
            $errors[] = 'Email already exists.';
        }

        return [
            'errors' => $errors,
            'email' => $email,
            'password' => $password,
        ];
    }

    private function buildCustomerFormContext(?array $override = null): array
    {
        $data = [
            'email' => '',
            'success' => null,
            'errors' => [],
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderCustomerFormWithErrors(array $formData, int $status): Response
    {
        return new Response($this->twig->render('admin/instances/_customer_form.html.twig', [
            'customerForm' => $this->buildCustomerFormContext([
                'email' => $formData['email'],
                'errors' => $formData['errors'],
            ]),
        ]), $status);
    }

    /**
     * @param Instance[] $instances
     */
    private function normalizeInstances(array $instances, array $sftpCredentials): array
    {
        return array_map(function (Instance $instance) use ($sftpCredentials): array {
            $diskLimitBytes = $instance->getDiskLimitBytes();
            $diskUsedBytes = $instance->getDiskUsedBytes();
            $diskPercent = $diskLimitBytes > 0 ? ($diskUsedBytes / $diskLimitBytes) * 100 : 0;
            $credential = $sftpCredentials[$instance->getId()] ?? null;

            return [
                'id' => $instance->getId(),
                'customer_email' => $instance->getCustomer()->getEmail(),
                'template_name' => $instance->getTemplate()->getDisplayName(),
                'game_key' => $instance->getTemplate()->getGameKey(),
                'node' => $instance->getNode()->getName() ?? $instance->getNode()->getId(),
                'cpu_limit' => $instance->getCpuLimit(),
                'ram_limit' => $instance->getRamLimit(),
                'disk_limit' => $instance->getDiskLimit(),
                'disk_limit_bytes' => $diskLimitBytes,
                'disk_used_bytes' => $diskUsedBytes,
                'disk_limit_human' => $this->diskUsageFormatter->formatBytes($diskLimitBytes),
                'disk_used_human' => $this->diskUsageFormatter->formatBytes($diskUsedBytes),
                'disk_percent' => $diskPercent,
                'disk_state' => $instance->getDiskState()->value,
                'disk_last_scanned_at' => $instance->getDiskLastScannedAt(),
                'status' => $instance->getStatus()->value,
                'created_at' => $instance->getCreatedAt(),
                'updated_at' => $instance->getUpdatedAt(),
                'sftp_username' => $credential?->getUsername(),
                'sftp_ready' => $credential !== null,
            ];
        }, $instances);
    }

    /**
     * @param Instance[] $instances
     *
     * @return array<int, InstanceSftpCredential>
     */
    private function buildSftpCredentialMap(array $instances): array
    {
        $credentials = $this->instanceSftpCredentialRepository->findByInstances($instances);
        $map = [];

        foreach ($credentials as $credential) {
            $instanceId = $credential->getInstance()->getId();
            if ($instanceId !== null) {
                $map[$instanceId] = $credential;
            }
        }

        return $map;
    }

    private function buildSftpUsername(Instance $instance): string
    {
        return sprintf('sftp%d', $instance->getId());
    }

    private function generateSftpPassword(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * @param Instance[] $instances
     */
    private function buildSummary(array $instances): array
    {
        $summary = [
            'total' => count($instances),
            'running' => 0,
            'pending_setup' => 0,
            'stopped' => 0,
            'suspended' => 0,
            'provisioning' => 0,
            'error' => 0,
        ];

        foreach ($instances as $instance) {
            $status = $instance->getStatus();
            if ($status === InstanceStatus::Running) {
                $summary['running']++;
            } elseif ($status === InstanceStatus::PendingSetup) {
                $summary['pending_setup']++;
            } elseif ($status === InstanceStatus::Stopped) {
                $summary['stopped']++;
            } elseif ($status === InstanceStatus::Suspended) {
                $summary['suspended'] = ($summary['suspended'] ?? 0) + 1;
            } elseif ($status === InstanceStatus::Provisioning) {
                $summary['provisioning']++;
            } elseif ($status === InstanceStatus::Error) {
                $summary['error']++;
            }
        }

        return $summary;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }
}
