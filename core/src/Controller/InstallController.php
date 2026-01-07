<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Site;
use App\Entity\User;
use App\Enum\UserType;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class InstallController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route(path: '/install', name: 'public_install', methods: ['GET', 'POST'])]
    public function install(Request $request): Response
    {
        $errors = [];
        $success = [];
        $completed = false;
        $status = Response::HTTP_OK;
        $hasAdmin = $this->hasAdminUser();
        $step = max(1, min(3, (int) $request->query->get('step', 1)));

        $form = [
            'db_driver' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_name' => 'easywi',
            'db_user' => '',
            'db_password' => '',
            'db_path' => $this->projectDir . '/var/data.db',
            'site_name' => 'Default Site',
            'site_host' => $request->getHost() ?: 'localhost',
            'admin_email' => '',
        ];

        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            $form = array_merge($form, [
                'db_driver' => (string) ($payload['db_driver'] ?? 'mysql'),
                'db_host' => trim((string) ($payload['db_host'] ?? '')),
                'db_port' => trim((string) ($payload['db_port'] ?? '')),
                'db_name' => trim((string) ($payload['db_name'] ?? '')),
                'db_user' => trim((string) ($payload['db_user'] ?? '')),
                'db_password' => (string) ($payload['db_password'] ?? ''),
                'db_path' => trim((string) ($payload['db_path'] ?? '')),
                'site_name' => trim((string) ($payload['site_name'] ?? '')),
                'site_host' => trim((string) ($payload['site_host'] ?? '')),
                'admin_email' => trim((string) ($payload['admin_email'] ?? '')),
            ]);

            $adminPassword = (string) ($payload['admin_password'] ?? '');
            $adminPasswordConfirm = (string) ($payload['admin_password_confirm'] ?? '');
            $step = max(1, min(3, (int) ($payload['step'] ?? $step)));
            $stepAction = (string) ($payload['step_action'] ?? '');

            if ($hasAdmin) {
                $errors[] = 'Installation is already completed.';
            }

            if ($step === 1 || $stepAction === 'install' || $stepAction === 'test') {
                if (!in_array($form['db_driver'], ['mysql', 'sqlite'], true)) {
                    $errors[] = 'Select a supported database driver.';
                }

                $requiredExtension = $form['db_driver'] === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
                if (!extension_loaded($requiredExtension)) {
                    $errors[] = sprintf('Database driver extension "%s" is not available on this server.', $requiredExtension);
                }

                if ($form['db_driver'] === 'sqlite') {
                    $sqlitePathError = $this->getSqlitePathError($form['db_path']);
                    if ($sqlitePathError !== null) {
                        $errors[] = $sqlitePathError;
                    }
                }

                $databaseConfig = $this->buildDatabaseConfig($form);
                if ($databaseConfig === null) {
                    $errors[] = 'Database settings are incomplete.';
                }
            } else {
                $databaseConfig = null;
            }

            if ($step === 2 || $stepAction === 'install') {
                if ($form['site_name'] === '' || $form['site_host'] === '') {
                    $errors[] = 'Site name and host are required.';
                }
            }

            if ($step === 3 || $stepAction === 'install') {
                if ($form['admin_email'] === '' || !filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Enter a valid admin email.';
                }

                if (mb_strlen($adminPassword) < 8) {
                    $errors[] = 'Admin password must be at least 8 characters long.';
                }

                if ($adminPassword !== $adminPasswordConfirm) {
                    $errors[] = 'Admin passwords do not match.';
                }
            }

            if ($errors === [] && $stepAction === 'test' && $databaseConfig !== null) {
                try {
                    $this->testDatabaseConnection($databaseConfig['connection']);
                    $success[] = 'Database connection successful.';
                } catch (DbalException $exception) {
                    $errors[] = 'Database connection failed: ' . $exception->getMessage();
                } catch (\Throwable $exception) {
                    $errors[] = 'Installation failed: ' . $exception->getMessage();
                }
            } elseif ($errors === [] && $stepAction === 'install' && $databaseConfig !== null) {
                try {
                    $installEntityManager = $this->createInstallEntityManager($databaseConfig['connection']);
                    $this->ensureSchema($installEntityManager);

                    $siteRepository = $installEntityManager->getRepository(Site::class);
                    $site = $siteRepository->findOneBy(['host' => $form['site_host']]);
                    if ($site === null) {
                        $site = new Site($form['site_name'], $form['site_host']);
                        $installEntityManager->persist($site);
                    }

                    $userRepository = $installEntityManager->getRepository(User::class);
                    $admin = $userRepository->findOneBy(['type' => UserType::Admin->value]);
                    if ($admin === null) {
                        $admin = new User($form['admin_email'], UserType::Admin);
                        $admin->setPasswordHash($this->passwordHasher->hashPassword($admin, $adminPassword));
                        $installEntityManager->persist($admin);
                    }

                    $installEntityManager->flush();

                    $this->writeEnvLocal($databaseConfig['url']);

                    $completed = true;
                    $hasAdmin = true;
                } catch (DbalException $exception) {
                    $errors[] = 'Database connection failed: ' . $exception->getMessage();
                } catch (\Throwable $exception) {
                    $errors[] = 'Installation failed: ' . $exception->getMessage();
                }
            } elseif ($errors === [] && $stepAction === 'next') {
                $step = min(3, $step + 1);
            } elseif ($errors === [] && $stepAction === 'back') {
                $step = max(1, $step - 1);
            } elseif ($errors !== [] && $stepAction !== '') {
                $status = Response::HTTP_BAD_REQUEST;
            }
        }

        return new Response($this->twig->render('public/install/index.html.twig', [
            'errors' => $errors,
            'success' => $success,
            'form' => $form,
            'completed' => $completed,
            'hasAdmin' => $hasAdmin,
            'step' => $step,
            'phpVersion' => PHP_VERSION,
            'phpExtensions' => $this->getPhpExtensions(),
        ]), $status);
    }

    /**
     * @return list<string>
     */
    private function getPhpExtensions(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);

        return $extensions;
    }

    private function hasAdminUser(): bool
    {
        try {
            $userRepository = $this->entityManager->getRepository(User::class);
            $admin = $userRepository->findOneBy(['type' => UserType::Admin->value]);

            return $admin !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws DbalException
     */
    /**
     * @param array<string, mixed> $databaseConfig
     */
    private function createInstallEntityManager(array $databaseConfig): EntityManagerInterface
    {
        $connection = DriverManager::getConnection($databaseConfig);

        return new EntityManager($connection, $this->entityManager->getConfiguration(), $this->entityManager->getEventManager());
    }

    private function ensureSchema(EntityManagerInterface $installEntityManager): void
    {
        $schemaManager = $installEntityManager->getConnection()->createSchemaManager();
        if ($schemaManager->tablesExist(['users'])) {
            return;
        }

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        if ($metadata === []) {
            throw new \RuntimeException('No metadata found for schema creation.');
        }

        $schemaTool = new SchemaTool($installEntityManager);
        $schemaTool->createSchema($metadata);
    }

    /**
     * @return array{url: string, connection: array<string, mixed>}|null
     */
    private function buildDatabaseConfig(array $form): ?array
    {
        if ($form['db_driver'] === 'sqlite') {
            if ($form['db_path'] === '') {
                return null;
            }

            $path = $form['db_path'];
            if (!str_starts_with($path, '/')) {
                $path = $this->projectDir . '/' . $path;
            }

            return [
                'url' => 'sqlite:///' . ltrim($path, '/'),
                'connection' => [
                    'driver' => 'pdo_sqlite',
                    'path' => $path,
                ],
            ];
        }

        if ($form['db_driver'] === 'mysql') {
            if ($form['db_host'] === '' || $form['db_name'] === '' || $form['db_user'] === '') {
                return null;
            }

            $port = null;
            if ($form['db_port'] !== '' && is_numeric($form['db_port'])) {
                $port = (int) $form['db_port'];
            }

            $user = rawurlencode($form['db_user']);
            $password = $form['db_password'] !== '' ? ':' . rawurlencode($form['db_password']) : '';
            $host = $form['db_host'];
            $database = rawurlencode($form['db_name']);
            $portSuffix = $port !== null ? ':' . $port : '';

            return [
                'url' => sprintf(
                    'mysql://%s%s@%s%s/%s?serverVersion=8.0&charset=utf8mb4',
                    $user,
                    $password,
                    $host,
                    $portSuffix,
                    $database,
                ),
                'connection' => array_filter([
                    'driver' => 'pdo_mysql',
                    'host' => $form['db_host'],
                    'port' => $port,
                    'user' => $form['db_user'],
                    'password' => $form['db_password'] !== '' ? $form['db_password'] : null,
                    'dbname' => $form['db_name'],
                    'serverVersion' => '8.0',
                    'charset' => 'utf8mb4',
                ], static fn ($value) => $value !== null && $value !== ''),
            ];
        }

        return null;
    }

    /**
     * @throws DbalException
     */
    private function testDatabaseConnection(array $databaseConfig): void
    {
        $connection = DriverManager::getConnection($databaseConfig);
        $connection->getNativeConnection();
        $connection->executeQuery('SELECT 1');
    }

    private function getSqlitePathError(string $path): ?string
    {
        if ($path === '') {
            return 'SQLite path is required.';
        }

        if (!str_starts_with($path, '/')) {
            $path = $this->projectDir . '/' . $path;
        }

        if (file_exists($path)) {
            return is_writable($path) ? null : 'SQLite database file is not writable.';
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            return 'SQLite database directory does not exist.';
        }

        if (!is_writable($directory)) {
            return 'SQLite database directory is not writable.';
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return 'SQLite database file could not be created.';
        }

        fclose($handle);

        return null;
    }

    private function writeEnvLocal(string $databaseUrl): void
    {
        $path = $this->projectDir . '/.env.local';
        $contents = "DATABASE_URL=\"{$databaseUrl}\"\n";

        file_put_contents($path, $contents);
    }
}
