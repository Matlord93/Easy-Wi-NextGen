<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\DatabaseTableService;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DatabaseNodeRepository;
use App\Repository\DatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/databases')]
final class CustomerDatabaseController
{
    public function __construct(
        private readonly DatabaseRepository $databaseRepository,
        private readonly DatabaseNodeRepository $databaseNodeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly DatabaseProvisioningService $provisioningService,
        private readonly DatabaseNamingPolicy $namingPolicy,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly DatabaseTableService $databaseTableService,
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
        $errors = array_merge($errors, $this->namingPolicy->validateDatabaseName($name));

        $databaseLimit = $customer->getDatabaseLimit();
        $databaseCount = $this->databaseRepository->count(['customer' => $customer]);
        if ($databaseLimit > 0 && $databaseCount >= $databaseLimit) {
            $errors[] = 'Database limit reached.';
        }

        if ($errors !== []) {
            return $this->renderWithErrors($customer, $errors);
        }

        $scopedName = $this->namingPolicy->buildCustomerScopedName($customer->getId(), $name);
        $errors = array_merge($errors, $this->namingPolicy->validateDatabaseName($scopedName));
        if ($errors !== []) {
            return $this->renderWithErrors($customer, $errors);
        }

        $database = new Database(
            $customer,
            $node->getEngine(),
            $node->getHost(),
            $node->getPort(),
            $scopedName,
            $scopedName,
            null,
            $node,
        );

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $jobs = $this->provisioningService->buildCreateJobs($database, $node->getAgent()->getId());
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
        if ($database === null || !$this->isOwner($customer, $database)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        if ($this->isSystemDatabase($database->getName())) {
            return $this->renderWithErrors($customer, ['customer_databases_delete_system_forbidden']);
        }

        $agentId = $database->getNode()?->getAgent()->getId() ?? '';
        $job = $this->provisioningService->buildDeleteJob($database, $agentId);
        $database->setStatus('delete_pending');
        $database->setLastError(null, null);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'database.deleted', [
            'database_id' => $database->getId(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
            'database_node_id' => $database->getNode()?->getId(),
            'agent_id' => $agentId,
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/databases']);
    }

    #[Route(path: '/{id}/password/reset', name: 'customer_databases_password_reset', methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $database = $this->databaseRepository->find($id);
        if ($database === null || !$this->isOwner($customer, $database)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $agentId = $database->getNode()?->getAgent()->getId() ?? '';
        $job = $this->provisioningService->buildPasswordRotateJob($database, $agentId);
        $database->setStatus('rotation_pending');
        $database->setLastError(null, null);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'database.password_reset', [
            'database_id' => $database->getId(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
            'database_node_id' => $database->getNode()?->getId(),
            'agent_id' => $agentId,
        ]);

        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/databases']);
    }



    #[Route(path: '/{id}/tables', name: 'customer_databases_tables', methods: ['GET'])]
    public function tables(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $database = $this->databaseRepository->find($id);
        if ($database === null || !$this->isOwner($customer, $database)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        if ($this->isSystemDatabase($database->getName())) {
            return new Response($this->translator->trans('customer_databases_tables_system_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $tables = $this->databaseTableService->listTables($database);

        return new Response($this->twig->render('customer/databases/tables.html.twig', [
            'activeNav' => 'databases',
            'database' => $database,
            'tables' => $tables,
        ]));
    }


    #[Route(path: '/{id}/tables/{table}/structure', name: 'customer_databases_table_structure', methods: ['GET'])]
    public function tableStructure(Request $request, int $id, string $table): Response
    {
        $customer = $this->requireCustomer($request);
        $database = $this->databaseRepository->find($id);
        if ($database === null || !$this->isOwner($customer, $database)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        if ($this->isSystemDatabase($database->getName())) {
            return new Response($this->translator->trans('customer_databases_tables_system_forbidden'), Response::HTTP_FORBIDDEN);
        }

        try {
            $columns = $this->databaseTableService->describeTable($database, $table);
        } catch (\InvalidArgumentException) {
            return new Response($this->translator->trans('customer_databases_invalid_table_name'), Response::HTTP_BAD_REQUEST);
        }

        return new Response($this->twig->render('customer/databases/table_structure.html.twig', [
            'activeNav' => 'databases',
            'database' => $database,
            'table' => $table,
            'columns' => $columns,
        ]));
    }

    #[Route(path: '/{id}/tables/{table}/rows', name: 'customer_databases_table_rows', methods: ['GET'])]
    public function tableRows(Request $request, int $id, string $table): Response
    {
        $customer = $this->requireCustomer($request);
        $database = $this->databaseRepository->find($id);
        if ($database === null || !$this->isOwner($customer, $database)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        if ($this->isSystemDatabase($database->getName())) {
            return new Response($this->translator->trans('customer_databases_tables_system_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $limit = (int) $request->query->get('limit', 50);
        $offset = (int) $request->query->get('offset', 0);
        try {
            $result = $this->databaseTableService->listRows($database, $table, $limit, $offset);
        } catch (\InvalidArgumentException) {
            return new Response($this->translator->trans('customer_databases_invalid_table_name'), Response::HTTP_BAD_REQUEST);
        }

        return new Response($this->twig->render('customer/databases/table_rows.html.twig', [
            'activeNav' => 'databases',
            'database' => $database,
            'table' => $table,
            'rows' => $result['rows'],
            'limit' => $result['limit'],
            'offset' => $result['offset'],
        ]));
    }

    #[Route(path: '/{id}/export', name: 'customer_databases_export', methods: ['GET'])]
    public function exportDatabase(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $database = $this->databaseRepository->find($id);
        if ($database === null || !$this->isOwner($customer, $database)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        if ($this->isSystemDatabase($database->getName())) {
            return new Response($this->translator->trans('customer_databases_export_system_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $database->getName()) . '.sql';
        $lines = $this->databaseTableService->exportDatabaseSql($database);
        $response = new StreamedResponse(static function () use ($lines): void {
            foreach ($lines as $line) {
                echo $line . "\n";
            }
        });
        $response->headers->set('Content-Type', 'application/sql; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route(path: '/{id}/tables/{table}/export', name: 'customer_databases_table_export', methods: ['GET'])]
    public function exportTable(Request $request, int $id, string $table): Response
    {
        $customer = $this->requireCustomer($request);
        $database = $this->databaseRepository->find($id);
        if ($database === null || !$this->isOwner($customer, $database)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        if ($this->isSystemDatabase($database->getName())) {
            return new Response($this->translator->trans('customer_databases_export_system_forbidden'), Response::HTTP_FORBIDDEN);
        }

        try {
            $lines = $this->databaseTableService->exportTableSql($database, $table);
        } catch (\InvalidArgumentException) {
            return new Response($this->translator->trans('customer_databases_invalid_table_name'), Response::HTTP_BAD_REQUEST);
        }

        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $database->getName() . '_' . $table) . '.sql';
        $response = new StreamedResponse(static function () use ($lines): void {
            foreach ($lines as $line) {
                echo $line . "\n";
            }
        });
        $response->headers->set('Content-Type', 'application/sql; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route(path: '/{id}/import', name: 'customer_databases_import', methods: ['POST'])]
    public function importDatabase(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        $customer = ($actor instanceof User && $actor->getType() === UserType::Customer) ? $actor : null;

        $database = $this->databaseRepository->find($id);
        if ($database === null || $customer === null || !$this->isOwner($customer, $database)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        if ($this->isSystemDatabase($database->getName())) {
            return $this->renderWithErrors($customer, ['customer_databases_import_system_forbidden']);
        }

        $file = $request->files->get('sql_file');
        $filename = $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? (string) $file->getClientOriginalName() : '';
        $content = $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? (string) file_get_contents($file->getPathname()) : '';

        try {
            $this->databaseTableService->importSql($database, $filename, $content);
        } catch (\InvalidArgumentException $exception) {
            $allowed = [
                'import_invalid_extension',
                'import_file_too_large',
                'import_statement_blocked',
                'import_cross_database_blocked',
                'import_delimiter_not_supported',
                'invalid_table_name',
                'import_use_blocked',
                'import_statement_not_allowed',
            ];
            $code = in_array($exception->getMessage(), $allowed, true)
                ? $exception->getMessage()
                : 'customer_databases_import_failed';

            return $this->renderWithErrors($customer, [$code]);
        } catch (\Throwable) {
            return $this->renderWithErrors($customer, ['customer_databases_import_failed']);
        }

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/databases/'.$database->getId().'/tables']);
    }
    private function isOwner(User $customer, Database $database): bool
    {
        return $database->getCustomer() === $customer
            || ($customer->getId() !== null && $database->getCustomer()->getId() === $customer->getId());
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', $this->translator->trans('error_unauthorized'));
        }

        return $actor;
    }

    private function isSystemDatabase(string $name): bool
    {
        return in_array(strtolower(trim($name)), ['mysql', 'information_schema', 'performance_schema', 'sys'], true);
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
                'created_at' => $database->getCreatedAt(),
                'status' => $database->getStatus(),
            ];
        }, $databases);
    }
}
