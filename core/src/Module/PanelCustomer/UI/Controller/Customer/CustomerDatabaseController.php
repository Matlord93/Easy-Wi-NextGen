<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Repository\DatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/databases')]
final class CustomerDatabaseController
{
    private const ENGINES = ['mariadb', 'postgresql'];

    public function __construct(
        private readonly DatabaseRepository $databaseRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
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

        return new Response($this->twig->render('customer/databases/index.html.twig', [
            'activeNav' => 'databases',
            'databases' => $this->normalizeDatabases($databases),
            'databaseLimit' => $databaseLimit,
            'databaseCount' => $databaseCount,
            'limitReached' => $databaseLimit > 0 && $databaseCount >= $databaseLimit,
            'engines' => self::ENGINES,
        ]));
    }

    #[Route(path: '', name: 'customer_databases_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $engine = strtolower(trim((string) $request->request->get('engine', '')));
        $host = trim((string) $request->request->get('host', ''));
        $port = (int) $request->request->get('port', 0);
        $name = trim((string) $request->request->get('name', ''));
        $username = trim((string) $request->request->get('username', ''));
        $password = trim((string) $request->request->get('password', ''));

        $errors = [];
        if (!in_array($engine, self::ENGINES, true)) {
            $errors[] = 'Engine is invalid.';
        }
        if ($host === '') {
            $errors[] = 'Host is required.';
        }
        if ($port <= 0 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
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
            $engine,
            $host,
            $port,
            $name,
            $username,
            $encryptedPassword,
        );

        $this->entityManager->persist($database);
        $this->entityManager->flush();

        $job = $this->queueDatabaseJob('database.create', $database, [
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => (string) $database->getPort(),
            'database' => $database->getName(),
            'username' => $database->getUsername(),
            'encrypted_password' => $database->getEncryptedPassword(),
        ]);

        $this->auditLogger->log($customer, 'database.created', [
            'database_id' => $database->getId(),
            'customer_id' => $database->getCustomer()->getId(),
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => $database->getPort(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
        ]);

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

        return new Response($this->twig->render('customer/databases/index.html.twig', [
            'activeNav' => 'databases',
            'databases' => $this->normalizeDatabases($databases),
            'databaseLimit' => $databaseLimit,
            'databaseCount' => $databaseCount,
            'limitReached' => $databaseLimit > 0 && $databaseCount >= $databaseLimit,
            'engines' => self::ENGINES,
            'errors' => $errors,
        ]), Response::HTTP_BAD_REQUEST);
    }

    private function queueDatabaseJob(string $type, Database $database, array $extraPayload): Job
    {
        $payload = array_merge([
            'database_id' => (string) ($database->getId() ?? ''),
            'customer_id' => (string) $database->getCustomer()->getId(),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
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
                'updated_at' => $database->getUpdatedAt(),
            ];
        }, $databases);
    }
}
