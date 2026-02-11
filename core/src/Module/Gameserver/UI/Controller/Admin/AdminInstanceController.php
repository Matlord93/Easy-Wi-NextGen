<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Admin;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\DiskUsageFormatter;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;
use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\GameServerInstallPathManager;
use App\Module\Gameserver\Application\InstanceInstallService;
use App\Module\Gameserver\Application\InstanceQueryService;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Ports\Application\PortLeaseManager;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\AgentRepository;
use App\Repository\BackupScheduleRepository;
use App\Repository\InstanceMetricSampleRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Repository\InstanceSftpCredentialRepository;
use App\Repository\JobRepository;
use App\Repository\TemplateRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/instances')]
final class AdminInstanceController
{
    private readonly ?GameServerInstallPathManager $installPathManager;
    private const DEFAULT_PORT_POOL_START = 27015;
    private const DEFAULT_PORT_POOL_END = 27115;
    private const DEFAULT_PORT_POOL_TAG = 'gameserver';
    private const DEFAULT_PORT_POOL_NAME = 'Gameserver Standard';

    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceMetricSampleRepository $instanceMetricSampleRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly BackupScheduleRepository $backupScheduleRepository,
        private readonly JobRepository $jobRepository,
        private readonly UserRepository $userRepository,
        private readonly TemplateRepository $templateRepository,
        private readonly AgentRepository $agentRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly AppSettingsService $appSettingsService,
        private readonly DiskUsageFormatter $diskUsageFormatter,
        private readonly InstanceInstallService $instanceInstallService,
        private readonly InstanceQueryService $instanceQueryService,
        private readonly AgentGameServerClient $agentGameServerClient,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly PortLeaseManager $portLeaseManager,
        private readonly Environment $twig,
        ?GameServerInstallPathManager $installPathManager = null,
    ) {
        $this->installPathManager = $installPathManager;
    }

    #[Route(path: '', name: 'admin_instances', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instances = $this->instanceRepository->findBy([], ['updatedAt' => 'DESC']);
        $normalizedInstances = $this->normalizeInstances($instances, $this->buildSftpCredentialMap($instances));

        return new Response($this->twig->render('admin/instances/index.html.twig', [
            'instances' => $normalizedInstances,
            'summary' => $this->buildSummary($normalizedInstances),
            'ops' => $this->buildOpsSummary($normalizedInstances),
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

        return $this->renderInstancesTable($instances);
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

        $requiredPorts = $formData['template']->getRequiredPorts();
        $requiredCount = count($requiredPorts);
        $portBlock = $formData['port_block'];
        if ($portBlock === null && $requiredCount > 0) {
            $this->ensureDefaultPortPool($formData['node'], $actor);
            if ($formData['port_start'] !== null) {
                try {
                    $portBlock = $this->allocatePortBlockAt(
                        $formData['node'],
                        $formData['customer'],
                        $formData['port_start'],
                        $requiredCount,
                    );
                } catch (\RuntimeException | \InvalidArgumentException $exception) {
                    $formData['errors'][] = $exception->getMessage();
                    return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
                }
                if ($portBlock === null) {
                    $formData['errors'][] = 'Requested port does not match any enabled port pool.';
                    return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
                }
            } else {
                $portBlock = $this->allocatePortBlock($formData['node'], $formData['customer'], $requiredCount);
            }
            if ($portBlock === null) {
                $formData['errors'][] = 'No free port blocks available on the selected node.';
                return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
            }
        }

        $instance = new Instance(
            $formData['customer'],
            $formData['template'],
            $formData['node'],
            $formData['cpu_limit'],
            $formData['ram_limit'],
            $formData['disk_limit'],
            $portBlock?->getId(),
            InstanceStatus::PendingSetup,
            InstanceUpdatePolicy::Manual,
        );

        $instance->setSlots($formData['current_slots']);
        $instance->setMaxSlots($formData['max_slots']);
        $instance->setCurrentSlots($formData['current_slots']);
        $instance->setServerName($formData['server_name'] !== '' ? $formData['server_name'] : null);
        $instance->setGslKey($formData['steam_gslt'] !== '' ? $formData['steam_gslt'] : null);
        $instance->setSteamAccount($formData['steam_login_mode'] === 'account' && $formData['steam_account'] !== '' ? $formData['steam_account'] : null);
        $instance->setInstanceBaseDir($formData['instance_base_dir']);
        if ($formData['setup_vars'] !== []) {
            $instance->setSetupVars($formData['setup_vars']);
        }

        $this->entityManager->persist($instance);
        if ($portBlock !== null) {
            $this->entityManager->persist($portBlock);
        }
        $this->entityManager->flush();
        $this->installPathManager?->ensureInstallPath($instance);

        if ($portBlock !== null) {
            $portBlock->assignInstance($instance);
            $this->entityManager->persist($portBlock);
            $this->auditLogger->log($actor, 'port_block.assigned', [
                'port_block_id' => $portBlock->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $formData['customer']->getId(),
            ]);
        }

        $firewallJob = null;
        if ($portBlock !== null) {
            $ports = $portBlock->getPorts();
            if ($ports !== []) {
                $firewallJob = new Job('firewall.open_ports', [
                    'agent_id' => $formData['node']->getId(),
                    'instance_id' => (string) $instance->getId(),
                    'port_block_id' => $portBlock->getId(),
                    'ports' => implode(',', array_map('strval', $ports)),
                ]);
                $this->entityManager->persist($firewallJob);
            }
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
            'firewall_job_id' => $firewallJob?->getId(),
        ]);

        $this->entityManager->flush();

        if ($request->headers->get('HX-Request') === 'true') {
            $response = new Response('', Response::HTTP_NO_CONTENT);
            $response->headers->set('HX-Trigger', 'instances-changed');
            $response->headers->set('HX-Redirect', '/admin/instances');

            return $response;
        }

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/instances']);
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

        $deleteJob = new Job('instance.delete', [
            'agent_id' => $instance->getNode()->getId(),
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'install_path' => $instance->getInstallPath(),
            'base_dir' => $instance->getInstanceBaseDir(),
        ]);
        $this->entityManager->persist($deleteJob);
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
            'delete_job_id' => $deleteJob->getId(),
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
            $credential = new InstanceSftpCredential($instance, $username, $this->encryptionService->encrypt(bin2hex(random_bytes(24))));
            $credential->setRotatedAt(null);
            $credential->setExpiresAt((new \DateTimeImmutable('+30 days'))->setTimezone(new \DateTimeZone('UTC')));
            $this->entityManager->persist($credential);
            $this->entityManager->flush();

            $job = new Job('instance.sftp.credentials.reset', [
                'instance_id' => (string) $instance->getId(),
                'customer_id' => (string) $instance->getCustomer()->getId(),
                'agent_id' => $instance->getNode()->getId(),
                'credential_id' => $credential->getId(),
                'username' => $username,
                'rotate' => true,
                'expires_at' => $credential->getExpiresAt()?->format(DATE_RFC3339),
            ]);
            $this->entityManager->persist($job);

            $this->auditLogger->log($actor, 'instance.sftp.credentials.reset_requested', [
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'job_id' => $job->getId(),
                'username' => $username,
                'credential_id' => $credential->getId(),
                'rotate' => true,
            ]);
            $this->entityManager->flush();
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'instances-changed');

        return $response;
    }

    #[Route(path: '/{id}/resources', name: 'admin_instances_update_resources', methods: ['POST'])]
    public function updateResources(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $errors = [];
        $cpuLimitValue = $request->request->get('cpu_limit');
        $ramLimitValue = $request->request->get('ram_limit');
        $diskLimitValue = $request->request->get('disk_limit');

        if (!is_numeric($cpuLimitValue) || !is_numeric($ramLimitValue) || !is_numeric($diskLimitValue)) {
            $errors[] = 'CPU, RAM, and disk limits must be numeric.';
        }

        $cpuLimit = is_numeric($cpuLimitValue) ? (int) $cpuLimitValue : 0;
        $ramLimit = is_numeric($ramLimitValue) ? (int) $ramLimitValue : 0;
        $diskLimit = is_numeric($diskLimitValue) ? (int) $diskLimitValue : 0;

        if ($cpuLimit <= 0 || $ramLimit <= 0 || $diskLimit <= 0) {
            $errors[] = 'CPU, RAM, and disk limits must be positive.';
        }

        if ($cpuLimit < $instance->getCpuLimit()) {
            $errors[] = 'CPU limit cannot be decreased from its current value.';
        }

        if ($ramLimit < $instance->getRamLimit()) {
            $errors[] = 'RAM limit cannot be decreased from its current value.';
        }

        if ($diskLimit < $instance->getDiskLimit()) {
            $errors[] = 'Disk limit cannot be decreased from its current value.';
        }

        if ($errors !== []) {
            $instances = $this->instanceRepository->findBy([], ['updatedAt' => 'DESC']);

            return $this->renderInstancesTable($instances, $errors, Response::HTTP_BAD_REQUEST);
        }

        $instance->setCpuLimit($cpuLimit);
        $instance->setRamLimit($ramLimit);
        $instance->setDiskLimit($diskLimit);
        $this->entityManager->persist($instance);

        $this->auditLogger->log($actor, 'instance.resources.updated', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'cpu_limit' => $instance->getCpuLimit(),
            'ram_limit' => $instance->getRamLimit(),
            'disk_limit' => $instance->getDiskLimit(),
        ]);

        $this->entityManager->flush();

        $instances = $this->instanceRepository->findBy([], ['updatedAt' => 'DESC']);

        return $this->renderInstancesTable($instances);
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
        $portStartValue = $request->request->get('port_start');
        $maxSlotsValue = $request->request->get('max_slots');
        $currentSlotsValue = $request->request->get('current_slots');
        $serverName = trim((string) $request->request->get('server_name', ''));
        $serverPassword = (string) $request->request->get('server_password', '');
        $rconPassword = (string) $request->request->get('rcon_password', '');
        $steamGslt = trim((string) $request->request->get('steam_gslt', ''));
        $steamLoginMode = (string) $request->request->get('steam_login_mode', 'anonymous');
        $steamAccount = trim((string) $request->request->get('steam_account', ''));
        $steamPassword = (string) $request->request->get('steam_password', '');
        $instanceBaseDir = trim((string) $request->request->get('instance_base_dir', ''));

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

        $minSlots = $this->appSettingsService->getGameserverMinSlots();
        $maxSlotsLimit = $this->appSettingsService->getGameserverMaxSlots();
        $defaultSlots = $this->appSettingsService->getGameserverDefaultSlots();
        $defaultSlots = max($minSlots, min($defaultSlots, $maxSlotsLimit));

        $maxSlots = $maxSlotsLimit;
        if ($maxSlotsValue !== null && $maxSlotsValue !== '') {
            if (!is_numeric($maxSlotsValue)) {
                $errors[] = 'Max slots must be numeric.';
            } else {
                $maxSlots = (int) $maxSlotsValue;
            }
        }

        $currentSlots = $defaultSlots;
        if ($currentSlotsValue !== null && $currentSlotsValue !== '') {
            if (!is_numeric($currentSlotsValue)) {
                $errors[] = 'Current slots must be numeric.';
            } else {
                $currentSlots = (int) $currentSlotsValue;
            }
        }

        if ($maxSlots < $minSlots) {
            $errors[] = 'Max slots must be greater than or equal to the minimum slots.';
        }

        if ($maxSlots > $maxSlotsLimit) {
            $errors[] = 'Max slots exceeds the allowed maximum.';
        }

        if ($currentSlots < $minSlots || $currentSlots > $maxSlots) {
            $errors[] = 'Current slots must be within the allowed range.';
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

        if ($instanceBaseDir === '' && $node !== null) {
            $instanceBaseDir = $this->resolveDefaultInstanceBaseDir($node);
        }
        if ($instanceBaseDir !== '' && !$this->isAbsolutePath($instanceBaseDir)) {
            $errors[] = 'Instance base dir must be an absolute path.';
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

        $portStart = null;
        if ($portStartValue !== null && $portStartValue !== '') {
            if (!is_numeric($portStartValue)) {
                $errors[] = 'Port start must be numeric.';
            } else {
                $portStart = (int) $portStartValue;
                if ($portStart <= 0) {
                    $errors[] = 'Port start must be positive.';
                }
            }
        }

        if (!in_array($steamLoginMode, ['anonymous', 'account'], true)) {
            $steamLoginMode = 'anonymous';
        }

        if ($steamLoginMode === 'account') {
            if ($steamAccount === '') {
                $errors[] = 'Steam account username is required when using Steam login.';
            }
            if ($steamPassword === '') {
                $errors[] = 'Steam account password is required when using Steam login.';
            }
        }

        $setupVars = [];
        if ($serverPassword !== '') {
            $setupVars['SERVER_PASSWORD'] = $serverPassword;
        }
        if ($rconPassword !== '') {
            $setupVars['RCON_PASSWORD'] = $rconPassword;
        }
        if ($steamLoginMode === 'account' && $steamPassword !== '') {
            $setupVars['STEAM_PASSWORD'] = $steamPassword;
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
            'max_slots' => $maxSlots,
            'current_slots' => $currentSlots,
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'node_id' => $nodeId,
            'port_block_id' => $portBlockId ?? '',
            'port_start' => $portStart,
            'server_name' => $serverName,
            'server_password' => $serverPassword,
            'rcon_password' => $rconPassword,
            'steam_gslt' => $steamGslt,
            'steam_login_mode' => $steamLoginMode,
            'steam_account' => $steamAccount,
            'steam_password' => $steamPassword,
            'setup_vars' => $setupVars,
            'instance_base_dir' => $instanceBaseDir,
        ];
    }

    private function buildFormContext(?array $override = null): array
    {
        $portPoolNotice = null;
        if ($this->portPoolRepository->count(['enabled' => true]) === 0) {
            $portPoolNotice = [
                'start' => self::DEFAULT_PORT_POOL_START,
                'end' => self::DEFAULT_PORT_POOL_END,
            ];
        }

        $data = [
            'customer_id' => '',
            'template_id' => '',
            'node_id' => '',
            'cpu_limit' => 50,
            'ram_limit' => 4096,
            'disk_limit' => 20000,
            'port_block_id' => '',
            'port_start' => '',
            'current_slots' => $this->appSettingsService->getGameserverDefaultSlots(),
            'max_slots' => $this->appSettingsService->getGameserverMaxSlots(),
            'min_slots' => $this->appSettingsService->getGameserverMinSlots(),
            'max_slots_limit' => $this->appSettingsService->getGameserverMaxSlots(),
            'server_name' => '',
            'server_password' => '',
            'rcon_password' => '',
            'steam_gslt' => '',
            'steam_login_mode' => 'anonymous',
            'steam_account' => '',
            'steam_password' => '',
            'instance_base_dir' => $this->appSettingsService->getInstanceBaseDir(),
            'instance_base_dirs' => $this->getKnownInstanceBaseDirs(),
            'errors' => [],
            'port_pool_notice' => $portPoolNotice,
            'action_url' => '/admin/instances',
            'submit_label' => 'admin_instances_submit',
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        $data['current_slots'] = max($data['min_slots'], min($data['current_slots'], $data['max_slots_limit']));
        $data['max_slots'] = max($data['min_slots'], min($data['max_slots'], $data['max_slots_limit']));

        return $data;
    }

    private function allocatePortBlockAt(Agent $node, User $customer, int $startPort, int $requiredCount): ?PortBlock
    {
        $pools = $this->portPoolRepository->findEnabledByNode($node);
        $endPort = $startPort + $requiredCount - 1;

        foreach ($pools as $pool) {
            if ($startPort < $pool->getStartPort() || $endPort > $pool->getEndPort()) {
                continue;
            }

            $blocks = $this->portLeaseManager->allocateBlocksInRange($pool, $customer, $startPort, $endPort, $requiredCount);
            return $blocks[0] ?? null;
        }

        return null;
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
                'port_start' => $formData['port_start'],
                'current_slots' => $formData['current_slots'],
                'max_slots' => $formData['max_slots'],
                'server_name' => $formData['server_name'],
                'server_password' => $formData['server_password'],
                'rcon_password' => $formData['rcon_password'],
                'steam_gslt' => $formData['steam_gslt'],
                'steam_login_mode' => $formData['steam_login_mode'],
                'steam_account' => $formData['steam_account'],
                'steam_password' => $formData['steam_password'],
                'instance_base_dir' => $formData['instance_base_dir'],
                'errors' => $formData['errors'],
            ]),
        ]), $status);
    }

    /**
     * @return array<int, string>
     */
    private function getKnownInstanceBaseDirs(): array
    {
        $dirs = [$this->appSettingsService->getInstanceBaseDir()];
        $nodes = $this->agentRepository->findBy([], ['name' => 'ASC']);
        foreach ($nodes as $node) {
            $dirs = array_merge($dirs, $this->extractInstanceBaseDirs($node));
        }

        $unique = [];
        foreach ($dirs as $dir) {
            $normalized = trim($dir);
            if ($normalized === '' || isset($unique[$normalized])) {
                continue;
            }
            $unique[$normalized] = true;
        }

        return array_keys($unique);
    }

    /**
     * @return array<int, string>
     */
    private function extractInstanceBaseDirs(Agent $node): array
    {
        $metadata = $node->getMetadata();
        $dirs = is_array($metadata) ? ($metadata['instance_base_dirs'] ?? []) : [];
        if (!is_array($dirs)) {
            return [];
        }

        $normalized = [];
        foreach ($dirs as $dir) {
            if (!is_string($dir)) {
                continue;
            }
            $dir = trim($dir);
            if ($dir === '' || !$this->isAbsolutePath($dir)) {
                continue;
            }
            $normalized[$dir] = true;
        }

        return array_keys($normalized);
    }

    private function resolveDefaultInstanceBaseDir(Agent $node): string
    {
        $dirs = $this->extractInstanceBaseDirs($node);
        if ($dirs !== []) {
            return $dirs[0];
        }

        return $this->appSettingsService->getInstanceBaseDir();
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/');
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

    private function ensureDefaultPortPool(Agent $node, User $actor): void
    {
        $pools = $this->portPoolRepository->findEnabledByNode($node);
        if ($pools !== []) {
            return;
        }

        $pool = new \App\Module\Ports\Domain\Entity\PortPool(
            $node,
            self::DEFAULT_PORT_POOL_NAME,
            self::DEFAULT_PORT_POOL_TAG,
            self::DEFAULT_PORT_POOL_START,
            self::DEFAULT_PORT_POOL_END,
            true,
        );
        $this->entityManager->persist($pool);
        $this->auditLogger->log($actor, 'port_pool.created', [
            'port_pool_id' => $pool->getId(),
            'node_id' => $node->getId(),
            'name' => $pool->getName(),
            'tag' => $pool->getTag(),
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
        ]);
        $this->entityManager->flush();
    }

    /**
     * @param Instance[] $instances
     */
    private function normalizeInstances(array $instances, array $sftpCredentials): array
    {
        $portBlocks = $this->portBlockRepository->findByInstances($instances);
        $portBlockIndex = [];
        foreach ($portBlocks as $portBlock) {
            $assignedInstance = $portBlock->getInstance();
            if ($assignedInstance !== null) {
                $portBlockIndex[$assignedInstance->getId()] = $portBlock;
            }
        }

        return array_map(function (Instance $instance) use ($sftpCredentials, $portBlockIndex): array {
            $diskLimitBytes = $instance->getDiskLimitBytes();
            $diskUsedBytes = $instance->getDiskUsedBytes();
            $diskPercent = $diskLimitBytes > 0 ? ($diskUsedBytes / $diskLimitBytes) * 100 : 0;
            $credential = $sftpCredentials[$instance->getId()] ?? null;
            $installStatus = $this->instanceInstallService->getInstallStatus($instance);
            $portBlock = $portBlockIndex[$instance->getId()] ?? null;
            $querySnapshot = $this->instanceQueryService->getSnapshot($instance, $portBlock, false);
            $runtimeState = $this->resolveRuntimeState($instance, $querySnapshot);
            $runtimeStatus = $runtimeState['status'];
            $displayStatus = $this->resolveDisplayStatus($instance, $runtimeStatus);
            $instanceMetric = $this->instanceMetricSampleRepository->findLatestForInstance($instance);
            $bookedRamBytes = (int) $instance->getRamLimit() * 1024 * 1024;
            $usedRamBytes = $instanceMetric?->getMemUsedBytes();

            return [
                'id' => $instance->getId(),
                'customer_email' => $instance->getCustomer()->getEmail(),
                'template_name' => $instance->getTemplate()->getDisplayName(),
                'game_key' => $instance->getTemplate()->getGameKey(),
                'node' => $instance->getNode()->getName() ?? $instance->getNode()->getId(),
                'cpu_limit' => $instance->getCpuLimit(),
                'ram_limit' => $instance->getRamLimit(),
                'booked_cpu_cores' => (float) $instance->getCpuLimit(),
                'booked_ram_bytes' => $bookedRamBytes,
                'booked_ram_mb' => $instance->getRamLimit(),
                'disk_limit' => $instance->getDiskLimit(),
                'disk_limit_bytes' => $diskLimitBytes,
                'disk_used_bytes' => $diskUsedBytes,
                'disk_limit_human' => $this->diskUsageFormatter->formatBytes($diskLimitBytes),
                'disk_used_human' => $this->diskUsageFormatter->formatBytes($diskUsedBytes),
                'disk_percent' => $diskPercent,
                'disk_state' => $instance->getDiskState()->value,
                'disk_last_scanned_at' => $instance->getDiskLastScannedAt(),
                'disk_scan_error' => $instance->getDiskScanError(),
                'status' => $instance->getStatus()->value,
                'display_status' => $displayStatus,
                'runtime_status' => $runtimeStatus,
                'runtime_status_reason' => $runtimeState['reason'],
                'runtime_status_error_code' => $runtimeState['error_code'],
                'runtime_status_last_checked_at' => $runtimeState['checked_at'],
                'instance_cpu_percent' => $instanceMetric?->getCpuPercent(),
                'instance_mem_used_bytes' => $usedRamBytes,
                'instance_mem_percent' => ($usedRamBytes !== null && $bookedRamBytes > 0) ? (($usedRamBytes / $bookedRamBytes) * 100) : null,
                'current_slots' => $instance->getCurrentSlots(),
                'max_slots' => $instance->getMaxSlots(),
                'lock_slots' => $instance->isLockSlots(),
                'created_at' => $instance->getCreatedAt(),
                'updated_at' => $instance->getUpdatedAt(),
                'sftp_username' => $credential?->getUsername(),
                'sftp_ready' => $credential !== null,
                'install_ready' => $installStatus['is_ready'] ?? false,
                'install_error_code' => $installStatus['error_code'] ?? null,
            ];
        }, $instances);
    }

    private function resolveDisplayStatus(Instance $instance, ?string $runtimeStatus): string
    {
        $status = $instance->getStatus();
        if (in_array($status, [InstanceStatus::PendingSetup, InstanceStatus::Provisioning, InstanceStatus::Suspended, InstanceStatus::Error], true)) {
            return $status->value;
        }

        if ($runtimeStatus === InstanceStatus::Running->value) {
            return InstanceStatus::Running->value;
        }

        if ($runtimeStatus === InstanceStatus::Stopped->value) {
            return InstanceStatus::Stopped->value;
        }

        return $status->value;
    }

    /**
     * @param array<string, mixed> $querySnapshot
     * @return array{status: ?string, reason: ?string, error_code: ?string, checked_at: string}
     */
    private function resolveRuntimeState(Instance $instance, array $querySnapshot): array
    {
        $checkedAt = (new \DateTimeImmutable())->format(DATE_ATOM);

        try {
            $status = $this->agentGameServerClient->getInstanceStatus($instance);
        } catch (\Throwable $exception) {
            $queryStatus = is_string($querySnapshot['status'] ?? null) ? strtolower((string) $querySnapshot['status']) : null;

            return [
                'status' => $queryStatus,
                'reason' => sprintf('Agent status probe failed: %s', $exception->getMessage()),
                'error_code' => 'agent_status_probe_failed',
                'checked_at' => $checkedAt,
            ];
        }

        $statusValue = $status['status'] ?? null;
        if (!is_string($statusValue)) {
            return [
                'status' => is_string($querySnapshot['status'] ?? null) ? strtolower((string) $querySnapshot['status']) : null,
                'reason' => 'Agent status response missing status field.',
                'error_code' => 'agent_status_missing_field',
                'checked_at' => $checkedAt,
            ];
        }

        return [
            'status' => strtolower($statusValue),
            'reason' => null,
            'error_code' => null,
            'checked_at' => $checkedAt,
        ];
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
            $status = $instance['display_status'] ?? $instance['status'] ?? null;
            if ($status === InstanceStatus::Running->value) {
                $summary['running']++;
            } elseif ($status === InstanceStatus::PendingSetup->value) {
                $summary['pending_setup']++;
            } elseif ($status === InstanceStatus::Stopped->value) {
                $summary['stopped']++;
            } elseif ($status === InstanceStatus::Suspended->value) {
                $summary['suspended'] = ($summary['suspended'] ?? 0) + 1;
            } elseif ($status === InstanceStatus::Provisioning->value) {
                $summary['provisioning']++;
            } elseif ($status === InstanceStatus::Error->value) {
                $summary['error']++;
            }
        }

        return $summary;
    }

    private function buildOpsSummary(array $instances): array
    {
        $offlineAgents = [];
        $diskProblemCount = 0;
        $runtimeUnknownCount = 0;

        foreach ($instances as $instance) {
            if (($instance['disk_state'] ?? 'ok') !== 'ok') {
                $diskProblemCount++;
            }

            $runtimeStatus = (string) ($instance['runtime_status'] ?? '');
            if ($runtimeStatus === '' || $runtimeStatus === 'unknown') {
                $runtimeUnknownCount++;
            }

            if ((string) ($instance['runtime_status_error_code'] ?? '') === 'agent_status_probe_failed') {
                $offlineAgents[(string) ($instance['node'] ?? 'unknown')] = true;
            }
        }

        $failedJobs = [];
        foreach ($this->jobRepository->findLatest(300) as $job) {
            if ($job->getStatus()->value !== 'failed') {
                continue;
            }

            $errorCode = $job->getLastErrorCode() ?? 'unknown';
            $failedJobs[$errorCode] = ($failedJobs[$errorCode] ?? 0) + 1;
        }
        arsort($failedJobs);

        $blockedSchedules = 0;
        foreach ($this->instanceScheduleRepository->findBy([], ['updatedAt' => 'DESC'], 500) as $schedule) {
            if (!in_array($schedule->getLastStatus(), ['blocked', 'skipped'], true)) {
                continue;
            }
            $blockedSchedules++;
        }
        foreach ($this->backupScheduleRepository->findBy([], ['updatedAt' => 'DESC'], 500) as $schedule) {
            if (!in_array($schedule->getLastStatus(), ['blocked', 'skipped'], true)) {
                continue;
            }
            $blockedSchedules++;
        }

        return [
            'offline_agents' => count($offlineAgents),
            'disk_problem_instances' => $diskProblemCount,
            'runtime_unknown_instances' => $runtimeUnknownCount,
            'failed_jobs_by_error' => array_slice($failedJobs, 0, 5, true),
            'blocked_schedules' => $blockedSchedules,
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    /**
     * @param Instance[] $instances
     */
    private function renderInstancesTable(array $instances, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('admin/instances/_table.html.twig', [
            'instances' => $this->normalizeInstances($instances, $this->buildSftpCredentialMap($instances)),
            'errors' => $errors,
        ]), $status);
    }
}
