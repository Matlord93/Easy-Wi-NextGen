<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DatabaseNodeRepository;
use App\Repository\DatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/databases')]
final class CustomerDatabaseController
{
    public function __construct(
        private readonly DatabaseRepository $databaseRepository,
        private readonly DatabaseNodeRepository $databaseNodeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
        private readonly DatabaseProvisioningService $provisioningService,
        private readonly DatabaseNamingPolicy $namingPolicy,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_databases', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $databases = $this->databaseRepository->findByCustomer($customer);

        $databaseLimit = $customer->getDatabaseLimit();
        $databaseCount = count($databases);
        $databaseNodes = $this->databaseNodeRepository->findActiveByEngine();

        return new Response($this->twig->render('customer/databases/index.html.twig', [
            'activeNav' => 'databases',
            'databases' => $this->normalizeDatabases($databases),
            'databaseLimit' => $databaseLimit,
            'databaseCount' => $databaseCount,
            'limitReached' => $databaseLimit > 0 && $databaseCount >= $databaseLimit,
            'databaseNodes' => $databaseNodes,
        ]));
    }

    #[Route(path: '', name: 'customer_databases_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $nodeId = (int) $request->request->get('node_id', 0);
        $name = trim((string) $request->request->get('name', ''));
        $username = trim((string) $request->request->get('username', ''));
        $password = trim((string) $request->request->get('password', ''));

        $errors = [];
        $node = $nodeId > 0 ? $this->databaseNodeRepository->find($nodeId) : null;
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

        $databaseLimit = $customer->getDatabaseLimit();
        $databaseCount = $this->databaseRepository->count(['customer' => $customer]);
        if ($databaseLimit > 0 && $databaseCount >= $databaseLimit) {
            $errors[] = 'Database limit reached.';
        }

        if ($errors !== []) {
            return $this->renderWithErrors($customer, $errors);
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

        $jobs = $this->provisioningService->buildCreateJobs($database, $database->getEncryptedPassword(), $node->getAgent()->getId());
        foreach ($jobs as $job) {
            $this->entityManager->persist($job);
        }

        $this->auditLogger->log($customer, 'database.created', [
            'database_id' => $database->getId(),
            'customer_id' => $database->getCustomer()->getId(),
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => $database->getPort(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $jobs[0]->getId(),
            'database_node_id' => $node->getId(),
            'agent_id' => $node->getAgent()->getId(),
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/databases']);
    }

    #[Route(path: '/{id}/delete', name: 'customer_databases_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $database = $this->databaseRepository->find($id);
        if ($database === null || $database->getCustomer()->getId() !== $customer->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $agentId = $database->getNode()?->getAgent()->getId() ?? '';
        $job = $this->provisioningService->buildDeleteJob($database, $agentId);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'database.deleted', [
            'database_id' => $database->getId(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
            'database_node_id' => $database->getNode()?->getId(),
            'agent_id' => $agentId,
        ]);

        $this->entityManager->remove($database);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/databases']);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function renderWithErrors(User $customer, array $errors = []): Response
    {
        $databases = $this->databaseRepository->findByCustomer($customer);
        $databaseLimit = $customer->getDatabaseLimit();
        $databaseCount = count($databases);
        $databaseNodes = $this->databaseNodeRepository->findActiveByEngine();

        return new Response($this->twig->render('customer/databases/index.html.twig', [
            'activeNav' => 'databases',
            'databases' => $this->normalizeDatabases($databases),
            'databaseLimit' => $databaseLimit,
            'databaseCount' => $databaseCount,
            'limitReached' => $databaseLimit > 0 && $databaseCount >= $databaseLimit,
            'databaseNodes' => $databaseNodes,
            'errors' => $errors,
        ]), Response::HTTP_BAD_REQUEST);
    }

    /**
     * @param Database[] $databases
     */
    private function normalizeDatabases(array $databases): array
    {
        return array_map(static function (Database $database): array {
            return [
                'id' => $database->getId(),
                'name' => $database->getName(),
                'engine' => $database->getEngine(),
                'host' => $database->getHost(),
                'port' => $database->getPort(),
                'username' => $database->getUsername(),
                'node' => $database->getNode() === null ? null : [
                    'id' => $database->getNode()?->getId(),
                    'name' => $database->getNode()?->getName(),
                ],
                'updated_at' => $database->getUpdatedAt(),
            ];
        }, $databases);
    }
}
