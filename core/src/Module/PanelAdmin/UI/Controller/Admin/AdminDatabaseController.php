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
    private const ENGINES = ['mariadb', 'postgresql'];

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

        $databases = $this->databaseRepository->findBy([], ['updatedAt' => 'DESC']);
        $customers = $this->userRepository->findBy(['type' => UserType::Customer->value], ['email' => 'ASC']);
        $databaseNodes = $this->databaseNodeRepository->findBy([], ['updatedAt' => 'DESC']);
        $nodeCandidates = $this->databaseNodeRepository->findActiveByEngine();
        $agents = array_filter(
            $this->agentRepository->findBy([], ['updatedAt' => 'DESC']),
            static fn ($agent) => in_array('DB', $agent->getRoles(), true),
        );

        return new Response($this->twig->render('admin/databases/index.html.twig', [
            'databases' => $databases,
            'customers' => $customers,
            'engines' => self::ENGINES,
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
        $password = trim((string) $request->request->get('password', ''));

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
        if ($password === '' || mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        $errors = array_merge($errors, $this->namingPolicy->validateDatabaseName($name));
        $errors = array_merge($errors, $this->namingPolicy->validateUsername($username));

        if ($errors !== []) {
            return $this->renderWithErrors($errors);
        }

        $encryptedPassword = $this->encryptionService->encrypt($password);
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
        $jobs = $this->provisioningService->buildCreateJobs($database, $database->getEncryptedPassword(), $agentId);
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

        $password = trim((string) $request->request->get('password', ''));
        if ($password === '' || mb_strlen($password) < 8) {
            return $this->renderWithErrors(['Password must be at least 8 characters.']);
        }

        $encryptedPassword = $this->encryptionService->encrypt($password);
        $database->setEncryptedPassword($encryptedPassword);

        $agentId = $database->getNode()?->getAgent()->getId() ?? '';
        $job = $this->provisioningService->buildPasswordRotateJob($database, $database->getEncryptedPassword(), $agentId);
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
        $host = trim((string) $request->request->get('host', ''));
        $port = (int) $request->request->get('port', 0);
        $agentId = trim((string) $request->request->get('agent_id', ''));

        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!in_array($engine, self::ENGINES, true)) {
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
        $connection = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errNo, $errStr, $timeout);
        if (is_resource($connection)) {
            fclose($connection);
            $node->markHealthy('TCP connection OK.');
        } else {
            $error = sprintf('%s (%s)', $errStr ?: 'Connection failed', $errNo ?: 'n/a');
            $node->markUnhealthy($error);
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
        $databases = $this->databaseRepository->findBy([], ['updatedAt' => 'DESC']);
        $customers = $this->userRepository->findBy(['type' => UserType::Customer->value], ['email' => 'ASC']);
        $databaseNodes = $this->databaseNodeRepository->findBy([], ['updatedAt' => 'DESC']);
        $nodeCandidates = $this->databaseNodeRepository->findActiveByEngine();
        $agents = array_filter(
            $this->agentRepository->findBy([], ['updatedAt' => 'DESC']),
            static fn ($agent) => in_array('DB', $agent->getRoles(), true),
        );

        return new Response($this->twig->render('admin/databases/index.html.twig', [
            'databases' => $databases,
            'customers' => $customers,
            'engines' => self::ENGINES,
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
