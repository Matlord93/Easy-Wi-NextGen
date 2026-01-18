<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Module\Core\Domain\Entity\AppSetting;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Setup\Runtime\DatabaseConfig;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InstallerService
{
    private const SETUP_DIR = 'srv/setup';
    private const STATE_FILE = self::SETUP_DIR . '/state/install.state.json';
    private const LOCK_FILE = self::SETUP_DIR . '/state/install.lock';
    private const DEBUG_REPORT_FILE = self::SETUP_DIR . '/state/install.debug.json';
    private const SQLITE_DATA_FILE = self::SETUP_DIR . '/data/data.db';
    private const LOCALE_SESSION_KEY = 'installer.locale';

    public function __construct(
        private readonly EntityManagerInterface $defaultEntityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        private readonly \App\Module\Core\Application\GameTemplateSeeder $templateSeeder,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function checkRequirements(): array
    {
        $requirements = [];

        $requirements[] = [
            'key' => 'php_version',
            'ok' => PHP_VERSION_ID >= 80200,
            'required' => true,
            'messageKey' => 'requirements.php_version',
            'messageParams' => [
                '%current%' => PHP_VERSION,
                '%required%' => '8.4',
            ],
            'fixHintKey' => PHP_VERSION_ID >= 80200 ? null : 'hints.update_php',
            'fixHintParams' => [],
        ];

        foreach (['pdo_mysql', 'json', 'mbstring', 'intl'] as $extension) {
            $requirements[] = [
                'key' => 'ext_' . $extension,
                'ok' => extension_loaded($extension),
                'required' => true,
                'messageKey' => 'requirements.extension',
                'messageParams' => ['%extension%' => $extension],
                'fixHintKey' => extension_loaded($extension) ? null : 'hints.enable_extension',
                'fixHintParams' => ['%extension%' => $extension],
            ];
        }

        $sqliteAvailable = extension_loaded('pdo_sqlite');
        $requirements[] = [
            'key' => 'ext_pdo_sqlite',
            'ok' => $sqliteAvailable,
            'required' => false,
            'messageKey' => 'requirements.extension_optional',
            'messageParams' => ['%extension%' => 'pdo_sqlite'],
            'fixHintKey' => $sqliteAvailable ? null : 'hints.enable_extension',
            'fixHintParams' => ['%extension%' => 'pdo_sqlite'],
        ];

        $requirements[] = $this->buildWritableRequirement('var', $this->projectDir . '/var', 'hints.writable_var');
        $requirements[] = $this->buildWritableRequirement('var_cache', $this->projectDir . '/var/cache', 'hints.writable_cache');
        $requirements[] = $this->buildWritableRequirement(
            'srv_setup',
            $this->projectDir . '/' . self::SETUP_DIR,
            'hints.writable_var',
        );

        return $requirements;
    }

    /**
     * @param array<int, array<string, mixed>> $requirements
     */
    public function requirementsSatisfied(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if (!($requirement['required'] ?? false)) {
                continue;
            }

            if (!($requirement['ok'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function getPhpExtensions(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);

        return $extensions;
    }

    /**
     * @param array<string, mixed> $databaseState
     *
     * @return array<int, array<string, mixed>>
     */
    public function validateDatabaseInput(array $databaseState): array
    {
        $errors = [];

        if (!in_array($databaseState['driver'], ['mysql', 'sqlite'], true)) {
            $errors[] = ['key' => 'errors.db_driver_invalid'];
            return $errors;
        }

        $requiredExtension = $databaseState['driver'] === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        if (!extension_loaded($requiredExtension)) {
            $errors[] = [
                'key' => 'errors.missing_extension',
                'params' => ['%extension%' => $requiredExtension],
            ];
        }

        if ($databaseState['driver'] === 'sqlite') {
            $sqliteError = $this->getSqlitePathError((string) $databaseState['path']);
            if ($sqliteError !== null) {
                $errors[] = $sqliteError;
            }

            return $errors;
        }

        if ($databaseState['host'] === '' || $databaseState['name'] === '' || $databaseState['user'] === '') {
            $errors[] = ['key' => 'errors.db_missing_fields'];
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $databaseState
     *
     * @return array{url: string, connection: array<string, mixed>}
     */
    public function buildDatabaseConfig(array $databaseState): array
    {
        if ($databaseState['driver'] === 'sqlite') {
            $path = $this->resolveSqlitePath((string) $databaseState['path']);

            return [
                'url' => 'sqlite:///' . ltrim($path, '/'),
                'connection' => [
                    'driver' => 'pdo_sqlite',
                    'path' => $path,
                ],
            ];
        }

        $port = null;
        if ($databaseState['port'] !== '' && is_numeric($databaseState['port'])) {
            $port = (int) $databaseState['port'];
        }

        $user = rawurlencode((string) $databaseState['user']);
        $password = (string) $databaseState['password'];
        $passwordSegment = $password !== '' ? ':' . rawurlencode($password) : '';
        $host = (string) $databaseState['host'];
        $database = rawurlencode((string) $databaseState['name']);
        $portSuffix = $port !== null ? ':' . $port : '';

        return [
            'url' => sprintf(
                'mysql://%s%s@%s%s/%s?charset=utf8mb4',
                $user,
                $passwordSegment,
                $host,
                $portSuffix,
                $database,
            ),
            'connection' => array_filter([
                'driver' => 'pdo_mysql',
                'host' => $host,
                'port' => $port,
                'user' => (string) $databaseState['user'],
                'password' => $password !== '' ? $password : null,
                'dbname' => (string) $databaseState['name'],
                'charset' => 'utf8mb4',
            ], static fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /**
     * @param array<string, mixed> $databaseState
     */
    public function buildDatabaseUrl(array $databaseState): string
    {
        return $this->buildDatabaseConfig($databaseState)['url'];
    }

    /**
     * @param array<string, mixed> $databaseConfig
     *
     * @throws DbalException
     */
    public function testDbConnection(array $databaseConfig): string
    {
        $connection = DriverManager::getConnection($databaseConfig['connection']);
        $connection->executeQuery('SELECT 1');

        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            return (string) $connection->fetchOne('SELECT sqlite_version()');
        }

        return (string) $connection->fetchOne('SELECT VERSION()');
    }

    /**
     * @param array<string, mixed> $databaseConfig
     *
     * @throws DbalException
     */
    public function testDbPrivileges(array $databaseConfig): void
    {
        $connection = DriverManager::getConnection($databaseConfig['connection']);

        $platform = $connection->getDatabasePlatform();
        $tableName = '_install_priv_test';

        try {
            if ($platform instanceof SQLitePlatform) {
                $connection->executeStatement(sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY)', $tableName));
            } else {
                $connection->executeStatement(sprintf(
                    'CREATE TABLE %s (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                    $tableName,
                ));
            }
        } finally {
            try {
                $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tableName));
            } catch (\Throwable $exception) {
                $this->logger->warning('Failed to drop installer privilege test table.', [
                    'exception' => $exception,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $databaseState
     */
    public function storeDatabaseConfig(string $databaseUrl, array $databaseState): void
    {
        DatabaseConfig::write($this->projectDir, [
            'database_url' => $databaseUrl,
            'driver' => $databaseState['driver'] ?? null,
            'host' => $databaseState['host'] ?? null,
            'port' => $databaseState['port'] ?? null,
            'name' => $databaseState['name'] ?? null,
            'user' => $databaseState['user'] ?? null,
            'path' => $databaseState['path'] ?? null,
            'stored_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $_ENV['DATABASE_URL'] = $databaseUrl;
        $_SERVER['DATABASE_URL'] = $databaseUrl;
    }

    public function clearCache(): void
    {
        $cacheDir = $this->projectDir . '/var/cache';
        $realCacheDir = realpath($cacheDir);

        if ($realCacheDir === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realCacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getRealPath();
            if ($path === false || !str_starts_with($path, $realCacheDir . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($path);
                continue;
            }

            @unlink($path);
        }
    }

    public function runMigrations(EntityManagerInterface $entityManager): void
    {
        $config = new ConfigurationArray([
            'migrations_paths' => [
                'DoctrineMigrations' => $this->projectDir . '/migrations',
            ],
            'transactional' => false,
            'all_or_nothing' => false,
        ]);

        $dependencyFactory = DependencyFactory::fromEntityManager(
            $config,
            new ExistingEntityManager($entityManager),
        );

        $migrationConfiguration = $dependencyFactory->getConfiguration();
        if (method_exists($migrationConfiguration, 'setTransactional')) {
            $migrationConfiguration->setTransactional(false);
        }
        if (method_exists($migrationConfiguration, 'setAllOrNothing')) {
            $migrationConfiguration->setAllOrNothing(false);
        }

        $dependencyFactory->getMetadataStorage()->ensureInitialized();
        $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
        $targetVersion = $dependencyFactory->getVersionAliasResolver()
            ->resolveVersionAlias('latest');
        $plan = $planCalculator->getPlanUntilVersion($targetVersion);

        $migratorConfiguration = new MigratorConfiguration();
        $migratorConfiguration->setAllOrNothing(false);

        try {
            $dependencyFactory->getMigrator()->migrate($plan, $migratorConfiguration);
            $this->ensureSchemaExists($entityManager);
        } catch (TableExistsException $exception) {
            $this->logException($exception, 'Installer migrations skipped because tables already exist.');
            $this->ensureSchemaExists($entityManager);
        }
    }

    public function seedTemplates(EntityManagerInterface $entityManager): void
    {
        $this->templateSeeder->seed($entityManager);
    }

    private function ensureSchemaExists(EntityManagerInterface $entityManager): void
    {
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ($metadata === []) {
            return;
        }

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->updateSchema($metadata, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createSiteAndAdmin(EntityManagerInterface $entityManager, array $data, string $adminPassword): void
    {
        $siteRepository = $entityManager->getRepository(Site::class);
        $site = $siteRepository->findOneBy(['host' => $data['site_host']]);
        if ($site === null) {
            $site = new Site((string) $data['site_name'], (string) $data['site_host']);
            $entityManager->persist($site);
        }

        $userRepository = $entityManager->getRepository(User::class);
        $admin = $userRepository->findOneBy(['type' => UserType::Superadmin->value]);
        if ($admin === null) {
            $admin = $userRepository->findOneBy(['type' => UserType::Admin->value]);
        }
        if ($admin === null) {
            $admin = new User((string) $data['admin_email'], UserType::Superadmin);
            $admin->setName((string) ($data['admin_name'] ?? ''));
            $admin->setPasswordHash($this->passwordHasher->hashPassword($admin, $adminPassword));
            $entityManager->persist($admin);
        }

        $entityManager->flush();
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function storeAppSettings(EntityManagerInterface $entityManager, array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $setting = $entityManager->find(AppSetting::class, $key);
            if ($setting instanceof AppSetting) {
                $setting->setValue($value);
                continue;
            }

            $entityManager->persist(new AppSetting($key, $value));
        }

        $entityManager->flush();
    }

    /**
     * @return array<string, mixed>
     */
    public function readState(): array
    {
        $path = $this->projectDir . '/' . self::STATE_FILE;
        if (!file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function writeState(array $state): void
    {
        $path = $this->projectDir . '/' . self::STATE_FILE;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $contents = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($contents === false) {
            return;
        }

        $tempPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tempPath, $contents) === false) {
            return;
        }

        if (@rename($tempPath, $path) === false) {
            @unlink($tempPath);
        }
    }

    public function clearState(): void
    {
        $path = $this->projectDir . '/' . self::STATE_FILE;
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function writeDebugReport(array $payload): void
    {
        $path = $this->projectDir . '/' . self::DEBUG_REPORT_FILE;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($contents === false) {
            return;
        }

        $tempPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tempPath, $contents) === false) {
            return;
        }

        if (@rename($tempPath, $path) === false) {
            @unlink($tempPath);
        }
    }

    public function clearDebugReport(): void
    {
        $path = $this->projectDir . '/' . self::DEBUG_REPORT_FILE;
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    public function getDebugReportPath(): string
    {
        return $this->projectDir . '/' . self::DEBUG_REPORT_FILE;
    }

    public function isLocked(): bool
    {
        return file_exists($this->projectDir . '/' . self::LOCK_FILE);
    }

    public function writeLock(): void
    {
        $path = $this->projectDir . '/' . self::LOCK_FILE;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $contents = (new \DateTimeImmutable())->format(DATE_ATOM);
        file_put_contents($path, $contents . "\n");
    }

    public function resolveInstallerLocale(Request $request): string
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $rawLocale = $request->query->get('lang');

        if (is_string($rawLocale)) {
            $locale = strtolower(trim($rawLocale));
            if (in_array($locale, ['de', 'en'], true)) {
                if ($session !== null) {
                    $session->set(self::LOCALE_SESSION_KEY, $locale);
                }

                $request->setLocale($locale);
                return $locale;
            }
        }

        if ($session !== null && $session->has(self::LOCALE_SESSION_KEY)) {
            $locale = (string) $session->get(self::LOCALE_SESSION_KEY);
            if (in_array($locale, ['de', 'en'], true)) {
                $request->setLocale($locale);
                return $locale;
            }
        }

        $preferred = $request->getPreferredLanguage(['de', 'en']) ?? 'en';
        $request->setLocale($preferred);

        if ($session !== null) {
            $session->set(self::LOCALE_SESSION_KEY, $preferred);
        }

        return $preferred;
    }

    public function logException(\Throwable $exception, string $message): void
    {
        $this->logger->error($message, [
            'exception' => $exception,
        ]);
    }

    /**
     * @param array<string, mixed> $connectionParams
     */
    public function createInstallEntityManager(array $connectionParams): EntityManagerInterface
    {
        $connection = DriverManager::getConnection($connectionParams);

        return new EntityManager(
            $connection,
            $this->defaultEntityManager->getConfiguration(),
            $this->defaultEntityManager->getEventManager(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWritableRequirement(string $key, string $path, string $hintKey): array
    {
        $ok = is_dir($path) && is_writable($path);

        return [
            'key' => $key,
            'ok' => $ok,
            'required' => true,
            'messageKey' => 'requirements.writable',
            'messageParams' => ['%path%' => $path],
            'fixHintKey' => $ok ? null : $hintKey,
            'fixHintParams' => ['%path%' => $path],
        ];
    }

    private function resolveSqlitePath(string $path): string
    {
        if ($path === '') {
            return $this->projectDir . '/' . self::SQLITE_DATA_FILE;
        }

        if (!str_starts_with($path, '/')) {
            return $this->projectDir . '/' . $path;
        }

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getSqlitePathError(string $path): ?array
    {
        if ($path === '') {
            return ['key' => 'errors.sqlite_path_required'];
        }

        $path = $this->resolveSqlitePath($path);

        if (file_exists($path)) {
            return is_writable($path) ? null : ['key' => 'errors.sqlite_file_not_writable'];
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            return ['key' => 'errors.sqlite_directory_missing'];
        }

        if (!is_writable($directory)) {
            return ['key' => 'errors.sqlite_directory_not_writable'];
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return ['key' => 'errors.sqlite_file_create_failed'];
        }

        fclose($handle);

        return null;
    }
}
