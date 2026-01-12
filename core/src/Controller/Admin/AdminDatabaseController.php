<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Database;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\DatabaseRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\EncryptionService;
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
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly EncryptionService $encryptionService,
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

        return new Response($this->twig->render('admin/databases/index.html.twig', [
            'databases' => $databases,
            'customers' => $customers,
            'engines' => self::ENGINES,
            'activeNav' => 'databases',
        ]));
    }

    #[Route(path: '', name: 'admin_databases_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $customerId = (int) $request->request->get('customer_id', 0);
        $engine = strtolower(trim((string) $request->request->get('engine', '')));
        $host = trim((string) $request->request->get('host', ''));
        $port = (int) $request->request->get('port', 0);
        $name = trim((string) $request->request->get('name', ''));
        $username = trim((string) $request->request->get('username', ''));
        $password = trim((string) $request->request->get('password', ''));

        $errors = [];
        $customer = $customerId > 0 ? $this->userRepository->find($customerId) : null;

        if (!$customer instanceof User || $customer->getType() !== UserType::Customer) {
            $errors[] = 'Customer is required.';
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
        if ($name === '') {
            $errors[] = 'Database name is required.';
        }
        if ($username === '') {
            $errors[] = 'Username is required.';
        }
        if ($password === '' || mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($errors !== []) {
            return $this->renderWithErrors($errors);
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

        $this->auditLogger->log($actor, 'database.created', [
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

        return $this->renderWithErrors();
    }

    #[Route(path: '/{id}/password', name: 'admin_databases_password_reset', methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
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

        $job = $this->queueDatabaseJob('database.password.reset', $database, [
            'username' => $database->getUsername(),
            'encrypted_password' => $database->getEncryptedPassword(),
        ]);

        $this->auditLogger->log($actor, 'database.password_reset', [
            'database_id' => $database->getId(),
            'name' => $database->getName(),
            'username' => $database->getUsername(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return $this->renderWithErrors();
    }

    private function renderWithErrors(array $errors = []): Response
    {
        $databases = $this->databaseRepository->findBy([], ['updatedAt' => 'DESC']);
        $customers = $this->userRepository->findBy(['type' => UserType::Customer->value], ['email' => 'ASC']);

        return new Response($this->twig->render('admin/databases/index.html.twig', [
            'databases' => $databases,
            'customers' => $customers,
            'engines' => self::ENGINES,
            'errors' => $errors,
            'activeNav' => 'databases',
        ]), $errors !== [] ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->getType() === UserType::Admin;
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
}
