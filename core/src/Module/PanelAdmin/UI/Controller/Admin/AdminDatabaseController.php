<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\EngineType;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\DatabaseNodeRepository;
use App\Repository\DatabaseRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/databases')]
final class AdminDatabaseController
{
    public function __construct(
        private readonly DatabaseRepository $databaseRepository,
        private readonly DatabaseNodeRepository $databaseNodeRepository,
        private readonly AgentRepository $agentRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
        private readonly DatabaseProvisioningService $provisioningService,
        private readonly DatabaseNamingPolicy $namingPolicy,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_databases', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $databases = $this->databaseRepository->findBy([], ['updatedAt' => 'DESC'], 200);
        $customers = $this->userRepository->findBy(['type' => UserType::Customer->value], ['email' => 'ASC'], 200);
        $databaseNodes = $this->databaseNodeRepository->findBy([], ['updatedAt' => 'DESC'], 200);
        $nodeCandidates = $this->databaseNodeRepository->findActiveByEngine();
        $agents = array_filter(
            $this->agentRepository->findBy([], ['updatedAt' => 'DESC'], 200),
            static fn ($agent) => in_array('DB', $agent->getRoles(), true),
        );

        return new Response($this->twig->render('admin/databases/index.html.twig', [
            'databases' => $databases,
            'customers' => $customers,
            'engines' => EngineType::values(),
            'databaseNodes' => $databaseNodes,
            'nodeCandidates' => $nodeCandidates,
            'agents' => $agents,
            'activeNav' => 'databases',
        ]));
    }

    #[Route(path: '', name: 'admin_databases_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $customerId = (int) $request->request->get('customer_id', 0);
        $nodeId = (int) $request->request->get('node_id', 0);
        $name = trim((string) $request->request->get('name', ''));
        $username = trim((string) $request->request->get('username', ''));

        $errors = [];
        $customer = $customerId > 0 ? $this->userRepository->find($customerId) : null;
        $node = $nodeId > 0 ? $this->databaseNodeRepository->find($nodeId) : null;

        if (!$customer instanceof User || $customer->getType() !== UserType::Customer) {
            $errors[] = 'Customer is required.';
        }
        if (!$node instanceof DatabaseNode) {
            $errors[] = 'Database node is required.';
        } elseif (!$node->isActive()) {
            $errors[] = 'Database node is inactive.';
        }
        if ($name === '') {
            $errors[] = 'Database name is required.';
        }
        if ($username === '') {
            $errors[] = 'Username is required.';
        }
        $errors = array_merge($errors, $this->namingPolicy->validateDatabaseName($name));
        $errors = array_merge($errors, $this->namingPolicy->validateUsername($username));

        if ($customer instanceof User && $node instanceof DatabaseNode && $this->databaseRepository->findOneByCustomerAndName($customer, $node->getEngine(), $name) instanceof Database) {
            $errors[] = 'Database name already exists for this customer.';
        }
        if ($customer instanceof User && $node instanceof DatabaseNode && $this->databaseRepository->findOneByCustomerAndUsername($customer, $node->getEngine(), $username) instanceof Database) {
            $errors[] = 'Database username already exists for this customer.';
        }

        if ($errors !== []) {
            return $this->renderWithErrors($errors);
        }

        $encryptedPassword = null;
        $database = new Database(
            $customer,
            $node->getEngine(),
            $node->getHost(),
            $node->getPort(),
            $name,
            $username,
            $encryptedPassword,
            $node,
        );

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $agentId = $node->getAgent()->getId();
        $jobs = $this->provisioningService->buildCreateJobs($database, $agentId);
        foreach ($jobs as $job) {
            $this->entityManager->persist($job);
        }

        $this->auditLogger->log($actor, 'database.created', [
            'database_id' => $database->getId(),
            'customer_id' => $database->getCustomer()->getId(),
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => $database->getPort(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $jobs[0]->getId(),
            'database_node_id' => $node->getId(),
            'agent_id' => $agentId,
        ]);

        $this->entityManager->flush();

        return $this->renderWithErrors();
    }

    #[Route(path: '/{id}/password', name: 'admin_databases_password_reset', methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $database = $this->databaseRepository->find($id);
        if ($database === null) {
            return new Response('Database not found.', Response::HTTP_NOT_FOUND);
        }


        $encryptedPassword = null;
        $database->setEncryptedPassword($encryptedPassword);

        $agentId = $database->getNode()?->getAgent()->getId() ?? '';
        $job = $this->provisioningService->buildPasswordRotateJob($database, $agentId);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'database.password_reset', [
            'database_id' => $database->getId(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
            'database_node_id' => $database->getNode()?->getId(),
            'agent_id' => $agentId,
        ]);

        $this->entityManager->flush();

        return $this->renderWithErrors();
    }

    #[Route(path: '/{id}/delete', name: 'admin_databases_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $database = $this->databaseRepository->find($id);
        if ($database === null) {
            return new Response('Database not found.', Response::HTTP_NOT_FOUND);
        }

        $agentId = $database->getNode()?->getAgent()->getId() ?? '';
        $job = $this->provisioningService->buildDeleteJob($database, $agentId);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'database.deleted', [
            'database_id' => $database->getId(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
            'database_node_id' => $database->getNode()?->getId(),
            'agent_id' => $agentId,
        ]);

        $this->entityManager->remove($database);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/admin/databases']);
    }

    #[Route(path: '/nodes', name: 'admin_database_nodes_create', methods: ['POST'])]
    public function createNode(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $name = trim((string) $request->request->get('name', ''));
        $engine = strtolower(trim((string) $request->request->get('engine', '')));
        $tlsMode = strtolower(trim((string) $request->request->get('tls_mode', 'off')));
        $adminUser = trim((string) $request->request->get('admin_user', ''));
        $adminSecret = trim((string) $request->request->get('admin_secret', ''));
        $caCert = trim((string) $request->request->get('ca_cert', ''));
        $tags = array_filter(array_map('trim', explode(',', (string) $request->request->get('tags', ''))));
        $host = trim((string) $request->request->get('host', ''));
        $port = (int) $request->request->get('port', 0);
        $agentId = trim((string) $request->request->get('agent_id', ''));

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!in_array($engine, EngineType::values(), true)) {
            $errors[] = 'Engine is invalid.';
        }
        if ($host === '') {
            $errors[] = 'Host is required.';
        }
        if ($port <= 0 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
        }
        $agent = $agentId !== '' ? $this->agentRepository->find($agentId) : null;
        if ($agent === null) {
            $errors[] = 'Agent is required.';
        } elseif (!in_array('DB', $agent->getRoles(), true)) {
            $errors[] = 'Agent must have the DB role.';
        }

        if ($errors !== []) {
            return $this->renderWithErrors($errors);
        }

        $node = new DatabaseNode($name, $engine, $host, $port, $agent);
        $node->setTlsMode(in_array($tlsMode, ['off', 'required', 'verify_ca', 'verify_full'], true) ? $tlsMode : 'off');
        $node->setCaCert($caCert !== '' ? $caCert : null);
        $node->setTags($tags);
        $node->setAdminUser($adminUser !== '' ? $adminUser : null);
        if ($adminSecret !== '') {
            $node->setEncryptedAdminSecret($this->encryptionService->encrypt($adminSecret));
        }
        $this->entityManager->persist($node);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'database.node.created', [
            'database_node_id' => $node->getId(),
            'name' => $node->getName(),
            'engine' => $node->getEngine(),
            'host' => $node->getHost(),
            'port' => $node->getPort(),
            'agent_id' => $agent->getId(),
        ]);
        $this->entityManager->flush();

        return $this->renderWithErrors();
    }

    #[Route(path: '/nodes/{id}/toggle', name: 'admin_database_nodes_toggle', methods: ['POST'])]
    public function toggleNode(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->databaseNodeRepository->find($id);
        if ($node === null) {
            return new Response('Database node not found.', Response::HTTP_NOT_FOUND);
        }

        $node->setIsActive(!$node->isActive());
        $this->auditLogger->log($actor, 'database.node.toggled', [
            'database_node_id' => $node->getId(),
            'is_active' => $node->isActive(),
        ]);
        $this->entityManager->flush();

        return $this->renderWithErrors();
    }

    #[Route(path: '/nodes/{id}/health', name: 'admin_database_nodes_health', methods: ['POST'])]
    public function checkNodeHealth(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->databaseNodeRepository->find($id);
        if ($node === null) {
            return new Response('Database node not found.', Response::HTTP_NOT_FOUND);
        }

        $host = $node->getHost();
        $port = $node->getPort();
        $timeout = 2.5;
        $errorCode = null;
        try {
            $dsn = match (strtolower($node->getEngine())) {
                EngineType::PostgreSql->value => sprintf('pgsql:host=%s;port=%d;dbname=postgres', $host, $port),
                EngineType::MySql->value, EngineType::MariaDb->value => sprintf('mysql:host=%s;port=%d;dbname=information_schema;charset=utf8mb4', $host, $port),
                default => throw new \RuntimeException('Unsupported database engine for health check.'),
            };
            $secret = $node->getEncryptedAdminSecret() === null ? '' : $this->encryptionService->decrypt($node->getEncryptedAdminSecret());
            $pdo = new \PDO($dsn, (string) $node->getAdminUser(), $secret, [\PDO::ATTR_TIMEOUT => (int) $timeout]);
            $pdo->query('SELECT 1');
            $node->markHealthy('SELECT 1 ok');
        } catch (\PDOException $exception) {
            $message = strtolower($exception->getMessage());
            $errorCode = str_contains($message, 'ssl') || str_contains($message, 'tls') ? 'db_node_tls_failed' : (str_contains($message, 'access denied') ? 'db_node_auth_failed' : 'db_node_connection_failed');
            $node->markUnhealthy($errorCode);
        } catch (\Throwable) {
            $errorCode = 'db_node_timeout';
            $node->markUnhealthy($errorCode);
        }

        $this->auditLogger->log($actor, 'database.node.health_checked', [
            'database_node_id' => $node->getId(),
            'status' => $node->getHealthStatus(),
            'message' => $node->getHealthMessage(),
        ]);
        $this->entityManager->flush();

        return $this->renderWithErrors();
    }

    private function renderWithErrors(array $errors = []): Response
    {
        $databases = $this->databaseRepository->findBy([], ['updatedAt' => 'DESC'], 200);
        $customers = $this->userRepository->findBy(['type' => UserType::Customer->value], ['email' => 'ASC'], 200);
        $databaseNodes = $this->databaseNodeRepository->findBy([], ['updatedAt' => 'DESC'], 200);
        $nodeCandidates = $this->databaseNodeRepository->findActiveByEngine();
        $agents = array_filter(
            $this->agentRepository->findBy([], ['updatedAt' => 'DESC'], 200),
            static fn ($agent) => in_array('DB', $agent->getRoles(), true),
        );

        return new Response($this->twig->render('admin/databases/index.html.twig', [
            'databases' => $databases,
            'customers' => $customers,
            'engines' => EngineType::values(),
            'databaseNodes' => $databaseNodes,
            'nodeCandidates' => $nodeCandidates,
            'agents' => $agents,
            'errors' => $errors,
            'activeNav' => 'databases',
        ]), $errors !== [] ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }

}
