<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Infrastructure\Config\DbConfigProvider;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\AppSetting;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\Application\AdminSshKeyService;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
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
    private const LOCALE_SESSION_KEY = 'installer.locale';
    private const SECRET_SETTING_KEYS = [
        'sftp_password',
        'sftp_private_key',
        'sftp_private_key_passphrase',
    ];
    private const DB_HEALTHCHECK_ATTEMPTS = 8;
    private const DB_HEALTHCHECK_INITIAL_DELAY_MS = 250;

    public function __construct(
        private readonly EntityManagerInterface $defaultEntityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        private readonly \App\Module\Core\Application\GameTemplateSeeder $templateSeeder,
        private readonly AdminSshKeyService $sshKeyService,
        private readonly DbConfigProvider $configProvider,
        private readonly EncryptionService $encryptionService,
        private readonly InstallEnvBootstrap $installEnvBootstrap,
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

        $this->ensureSetupStateDirectory();

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

        $requirements[] = $this->buildWritableRequirement('var', $this->projectDir . '/var', 'hints.writable_var');
        $requirements[] = $this->buildWritableRequirement('var_cache', $this->projectDir . '/var/cache', 'hints.writable_cache');
        $requirements[] = $this->buildEnvLocalRequirement($this->projectDir . '/.env.local');
        $requirements[] = $this->buildWritableRequirement(
            'srv_setup',
            $this->projectDir . '/' . self::SETUP_DIR,
            'hints.writable_var',
        );
        $requirements[] = $this->buildWritableRequirement(
            'srv_setup_state',
            $this->getSetupStateDirPath(),
            'hints.writable_var',
        );
        $requirements[] = $this->buildWritableRequirement(
            'db_config',
            dirname($this->configProvider->getConfigPath()),
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

        if (!extension_loaded('pdo_mysql')) {
            $errors[] = [
                'key' => 'errors.missing_extension',
                'params' => ['%extension%' => 'pdo_mysql'],
            ];
        }

        if ($databaseState['host'] === '' || $databaseState['name'] === '' || $databaseState['user'] === '') {
            $errors[] = ['key' => 'errors.db_missing_fields'];
        }

        if (isset($databaseState['user']) && strtolower(trim((string) $databaseState['user'])) === 'root') {
            $errors[] = ['key' => 'errors.db_root_user_not_allowed'];
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
    public function buildPersistentDatabaseConfig(array $databaseState): array
    {
        $port = null;
        if ($databaseState['port'] !== '' && is_numeric($databaseState['port'])) {
            $port = (int) $databaseState['port'];
        }

        return array_filter([
            'host' => (string) $databaseState['host'],
            'port' => $port,
            'dbname' => (string) $databaseState['name'],
            'user' => (string) $databaseState['user'],
            'password' => (string) $databaseState['password'],
            'serverVersion' => isset($databaseState['version']) ? (string) $databaseState['version'] : null,
            'charset' => 'utf8mb4',
        ], static fn ($value) => $value !== null && $value !== '');
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

        $grants = $connection->fetchFirstColumn('SHOW GRANTS FOR CURRENT_USER');
        if ($grants === []) {
            throw new \RuntimeException('Unable to read grants for current MySQL user.');
        }

        $databaseName = strtolower((string) ($databaseConfig['connection']['dbname'] ?? ''));
        $requiredPrivileges = ['create', 'alter', 'index'];

        if (!$this->hasRequiredMySqlPrivileges($grants, $databaseName, $requiredPrivileges)) {
            throw new \RuntimeException('Missing required CREATE/ALTER/INDEX privileges for configured database.');
        }
    }

    /**
     * @param list<string> $grants
     * @param list<string> $requiredPrivileges
     */
    private function hasRequiredMySqlPrivileges(array $grants, string $databaseName, array $requiredPrivileges): bool
    {
        foreach ($grants as $grantRaw) {
            $grant = strtolower($grantRaw);

            if (!str_starts_with($grant, 'grant ')) {
                continue;
            }

            $onPos = strpos($grant, ' on ');
            $toPos = strpos($grant, ' to ');
            if ($onPos === false || $toPos === false || $toPos <= $onPos + 4) {
                continue;
            }

            $privilegeSegment = trim(substr($grant, 6, $onPos - 6));
            $scope = trim(substr($grant, $onPos + 4, $toPos - ($onPos + 4)));
            $scope = str_replace('`', '', $scope);

            if ($scope !== '*.*' && $scope !== $databaseName . '.*') {
                continue;
            }

            if ($privilegeSegment === 'all privileges' || $privilegeSegment === 'all') {
                return true;
            }

            $privileges = array_map('trim', explode(',', $privilegeSegment));
            $hasAll = true;
            foreach ($requiredPrivileges as $requiredPrivilege) {
                if (!in_array($requiredPrivilege, $privileges, true)) {
                    $hasAll = false;
                    break;
                }
            }

            if ($hasAll) {
                return true;
            }
        }

        return false;
    }


    /**
     * @return array{ok: bool, error_code?: string, env_path: string}
     */
    public function ensureEnvSecretsPresent(): array
    {
        return $this->installEnvBootstrap->ensure($this->projectDir);
    }


    /**
     * @return array{ok: bool, error_code?: string, env_path: string, written_keys?: list<string>}
     */
    public function ensureInstallerEnvironmentBootstrap(): array
    {
        return $this->ensureEnvSecretsPresent();
    }

    public function storeDatabaseConfig(array $payload): void
    {
        $this->ensureSecretKeyExists();
        $this->configProvider->store($payload);
    }


    private function ensureSecretKeyExists(): void
    {
        if ($this->configProvider->isKeyReadable()) {
            return;
        }

        $keyPath = $this->configProvider->getKeyPath();
        if (is_file($keyPath)) {
            throw new \RuntimeException('Secret key file is not readable.');
        }

        $directory = dirname($keyPath);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create secret key directory.');
        }

        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        if (file_put_contents($keyPath, $key . "\n") === false) {
            throw new \RuntimeException('Unable to write secret key file.');
        }

        @chmod($keyPath, 0600);
    }

    public function getDatabaseConfigPath(): string
    {
        return $this->configProvider->getConfigPath();
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
        $connectionParams = $entityManager->getConnection()->getParams();
        $this->waitForDatabaseReady($connectionParams);

        $result = $this->runInstallerCommand(
            'php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration',
        );

        if ($result['exitCode'] !== 0) {
            $this->logger->error('Installer migration command failed.', [
                'exit_code' => $result['exitCode'],
                'output' => $result['output'],
            ]);
            throw new \RuntimeException('Installer migration command failed: ' . $result['output']);
        }

        $this->ensureSchemaExists($entityManager);
    }

    public function initializeDatabase(EntityManagerInterface $entityManager): void
    {
        $this->runMigrations($entityManager);
        $this->seedTemplates($entityManager);
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
     * @param array<string, mixed> $connectionParams
     */
    private function waitForDatabaseReady(array $connectionParams): void
    {
        $delayMs = self::DB_HEALTHCHECK_INITIAL_DELAY_MS;

        for ($attempt = 1; $attempt <= self::DB_HEALTHCHECK_ATTEMPTS; $attempt++) {
            try {
                $connection = DriverManager::getConnection($connectionParams);
                $connection->executeQuery('SELECT 1');
                $connection->close();

                if ($attempt > 1) {
                    $this->logger->info('Installer DB healthcheck succeeded after retry.', [
                        'attempt' => $attempt,
                    ]);
                }

                return;
            } catch (\Throwable $exception) {
                $this->logger->warning('Installer DB healthcheck failed.', [
                    'attempt' => $attempt,
                    'max_attempts' => self::DB_HEALTHCHECK_ATTEMPTS,
                    'error' => $exception->getMessage(),
                ]);

                if ($attempt === self::DB_HEALTHCHECK_ATTEMPTS) {
                    throw new \RuntimeException('Database is not ready for migrations.', 0, $exception);
                }

                usleep($delayMs * 1000);
                $delayMs = min($delayMs * 2, 4000);
            }
        }
    }

    /**
     * @return array{exitCode: int, output: string}
     */
    private function runInstallerCommand(string $command): array
    {
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor, $pipes, $this->projectDir);
        if (!is_resource($process)) {
            return ['exitCode' => 1, 'output' => 'Failed to start migration process.'];
        }

        $output = '';
        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if (is_string($chunk)) {
                $output .= $chunk;
            }
            fclose($pipes[$index]);
        }

        $exitCode = proc_close($process);

        return [
            'exitCode' => is_int($exitCode) ? $exitCode : 1,
            'output' => trim($output),
        ];
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
            $admin->setAdminSshKeyEnabled(true);
            $entityManager->persist($admin);
        }

        $sshKey = trim((string) ($data['admin_ssh_key'] ?? ''));
        if ($sshKey !== '' && $admin->getAdminSshPublicKey() === null) {
            if ($admin->getId() === null) {
                $entityManager->flush();
            }
            try {
                $admin->setAdminSshPublicKeyPending($sshKey);
                if (!$this->sshKeyService->storeKey($admin, $sshKey)) {
                    $this->logger->warning('No agent available to store admin SSH key during installation.');
                }
            } catch (\Throwable $exception) {
                $this->logException($exception, 'Failed to store admin SSH key during installation.');
                throw new InstallerSshKeyException('Failed to store admin SSH key.');
            }
        }

        $settingsRepository = $entityManager->getRepository(CmsSiteSettings::class);
        $settings = $settingsRepository->findOneBy(['site' => $site]);
        if (!$settings instanceof CmsSiteSettings) {
            $settings = new CmsSiteSettings($site);
            $settings->setActiveTheme((string) ($data['cms_template'] ?? 'minimal'));
            $settings->setModuleTogglesJson([
                'blog' => true,
                'events' => true,
                'team' => true,
                'forum' => true,
                'media' => true,
                'gameserver' => true,
            ]);
            $entityManager->persist($settings);
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

            if (in_array($key, self::SECRET_SETTING_KEYS, true) && is_string($value)) {
                $value = trim($value) === '' ? null : $this->encryptionService->encrypt($value);
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

    public function getSetupStateDirPath(): string
    {
        return $this->projectDir . '/' . self::SETUP_DIR . '/state';
    }

    public function isLocked(): bool
    {
        return file_exists($this->projectDir . '/' . self::LOCK_FILE);
    }

    public function ensureSetupStateDirectory(): void
    {
        $path = $this->getSetupStateDirPath();
        if (is_dir($path)) {
            return;
        }

        if (@mkdir($path, 0775, true) || is_dir($path)) {
            return;
        }

        $this->logger->error('Unable to create setup state directory.', [
            'path' => $path,
        ]);
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
        if ($this->configProvider->exists()) {
            $payload = $this->configProvider->load();
            $validationErrors = $this->configProvider->validate($payload);
            if ($validationErrors !== []) {
                throw new \RuntimeException('Stored database configuration is invalid.');
            }

            $connectionParams = $this->configProvider->toConnectionParams($payload);
        }

        $connectionParams['driver'] = (string) ($connectionParams['driver'] ?? 'pdo_mysql');
        if ($connectionParams['driver'] !== 'pdo_mysql') {
            throw new \RuntimeException(sprintf('Unsupported installer database driver: %s', $connectionParams['driver']));
        }

        unset($connectionParams['url'], $connectionParams['path']);

        $connection = DriverManager::getConnection($connectionParams);

        return new EntityManager(
            $connection,
            $this->defaultEntityManager->getConfiguration(),
            new EventManager(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWritableRequirement(string $key, string $path, string $hintKey): array
    {
        $ok = $this->isDirectoryWritable($path);

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


    /**
     * @return array<string, mixed>
     */
    private function buildEnvLocalRequirement(string $path): array
    {
        $ok = is_file($path) ? is_writable($path) : $this->isDirectoryWritable(dirname($path));

        return [
            'key' => 'env_local',
            'ok' => $ok,
            'required' => true,
            'messageKey' => 'requirements.env_local',
            'messageParams' => ['%path%' => $path],
            'fixHintKey' => $ok ? null : 'hints.writable_env',
            'fixHintParams' => ['%path%' => $path],
        ];
    }

    private function isDirectoryWritable(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);

        return is_dir($parent) && is_writable($parent);
    }

}
