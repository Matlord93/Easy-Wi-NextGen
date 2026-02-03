<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Infrastructure\Config\DbConfigProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class UpdateJobService
{
    private const DEFAULT_BACKUP_LIMIT = 5;

    public function __construct(
        private readonly DbConfigProvider $configProvider,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly string $runnerCommand,
        private readonly string $jobsDir,
        private readonly string $logsDir,
        private readonly string $backupsDir,
        private readonly string $coreDir,
        private readonly ?string $defaultPackageUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createJob(string $type, ?string $createdBy, array $payload = []): array
    {
        $this->ensureDirectories();
        $id = Uuid::v4()->toRfc4122();
        $logPath = $this->logsDir . '/' . $id . '.log';
        $job = [
            'id' => $id,
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'createdBy' => $createdBy ?? 'system',
            'type' => $type,
            'status' => 'pending',
            'logPath' => $logPath,
            'startedAt' => null,
            'finishedAt' => null,
            'exitCode' => null,
            'payload' => $payload,
        ];

        $job['payload'] = $this->normalizePayload($job['payload']);

        $jobPath = $this->jobsDir . '/' . $id . '.json';
        $this->writeJob($jobPath, $job);
        if (!is_file($logPath)) {
            file_put_contents($logPath, '');
        }

        return $job;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getJob(string $id): ?array
    {
        $path = $this->jobsDir . '/' . $id . '.json';
        return $this->readJob($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatestJob(): ?array
    {
        if (!is_dir($this->jobsDir)) {
            return null;
        }

        $latestPath = null;
        $latestTime = 0;
        foreach (new \DirectoryIterator($this->jobsDir) as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'json') {
                continue;
            }
            $mtime = $fileInfo->getMTime();
            if ($mtime > $latestTime) {
                $latestTime = $mtime;
                $latestPath = $fileInfo->getPathname();
            }
        }

        return $latestPath ? $this->readJob($latestPath) : null;
    }

    /**
     * @return array<int, string>
     */
    public function tailLog(?string $logPath, int $lines = 200): array
    {
        if ($logPath === null || $logPath === '' || !is_file($logPath)) {
            return [];
        }

        $contents = file($logPath, FILE_IGNORE_NEW_LINES);
        if ($contents === false) {
            return [];
        }

        if (count($contents) <= $lines) {
            return $contents;
        }

        return array_slice($contents, -$lines);
    }

    /**
     * @return array<int, array{path: string, createdAt: string}>
     */
    public function listBackups(int $limit = self::DEFAULT_BACKUP_LIMIT): array
    {
        if (!is_dir($this->backupsDir)) {
            return [];
        }

        $entries = [];
        foreach (new \DirectoryIterator($this->backupsDir) as $fileInfo) {
            if (!$fileInfo->isDir() || $fileInfo->isDot()) {
                continue;
            }
            $entries[] = [
                'path' => $fileInfo->getPathname(),
                'createdAt' => date(\DateTimeInterface::ATOM, $fileInfo->getMTime()),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));

        return array_slice($entries, 0, $limit);
    }

    public function triggerRunner(string $jobId): bool
    {
        if (trim($this->runnerCommand) === '') {
            return false;
        }

        try {
            $process = Process::fromShellCommandline(
                sprintf('%s --run-job %s', $this->runnerCommand, escapeshellarg($jobId)),
                $this->coreDir,
                null,
                null,
                1,
            );
            $process->start();
        } catch (\Throwable $exception) {
            $this->logger->warning('update.runner_failed', [
                'job_id' => $jobId,
                'exception' => $exception,
            ]);
            return false;
        }

        return true;
    }

    /**
     * @return array{pending: int|null, executedUnavailable: int|null, error: string|null}
     */
    public function getMigrationStatus(): array
    {
        if (!$this->configProvider->exists()) {
            return ['pending' => null, 'executedUnavailable' => null, 'error' => 'db_not_configured'];
        }

        try {
            $payload = $this->configProvider->load();
            $errors = $this->configProvider->validate($payload);
            if ($errors !== []) {
                return ['pending' => null, 'executedUnavailable' => null, 'error' => 'db_invalid'];
            }
            $connectionParams = $this->configProvider->toConnectionParams($payload);
            $connection = DriverManager::getConnection($connectionParams);
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            $this->logger->warning('update.migration_status_failed', [
                'exception' => $exception,
            ]);
            return ['pending' => null, 'executedUnavailable' => null, 'error' => 'db_unreachable'];
        }

        try {
            $dependencyFactory = $this->buildDependencyFactory($connection);
            $statusCalculator = $dependencyFactory->getMigrationStatusCalculator();
            $pending = count($statusCalculator->getNewMigrations());
            $executedUnavailable = count($statusCalculator->getExecutedUnavailableMigrations());

            return [
                'pending' => $pending,
                'executedUnavailable' => $executedUnavailable,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('update.migration_status_failed', [
                'exception' => $exception,
            ]);
            return ['pending' => null, 'executedUnavailable' => null, 'error' => 'migration_status_failed'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getVersionInfo(): array
    {
        $version = $this->readVersionFile($this->projectDir . '/VERSION');
        $commit = $this->readGitCommit($this->projectDir);
        $build = $_SERVER['APP_CORE_VERSION'] ?? $_ENV['APP_CORE_VERSION'] ?? null;

        return [
            'version' => $version,
            'commit' => $commit,
            'build' => $build !== '' ? $build : null,
        ];
    }

    public function getCoreDir(): string
    {
        return $this->coreDir;
    }

    public function getJobsDir(): string
    {
        return $this->jobsDir;
    }

    public function getLogsDir(): string
    {
        return $this->logsDir;
    }

    public function getBackupsDir(): string
    {
        return $this->backupsDir;
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->jobsDir, $this->logsDir, $this->backupsDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        if (!array_key_exists('package_url', $payload) && $this->defaultPackageUrl !== null) {
            $payload['package_url'] = $this->defaultPackageUrl;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJob(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJob(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode update job payload.');
        }
        file_put_contents($path, $encoded . "\n");
    }

    private function readVersionFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));
        return $contents !== '' ? $contents : null;
    }

    private function readGitCommit(string $dir): ?string
    {
        $command = sprintf('git -C %s rev-parse --short HEAD 2>/dev/null', escapeshellarg($dir));
        $value = trim((string) shell_exec($command));
        return $value !== '' ? $value : null;
    }

    private function buildDependencyFactory(Connection $connection): DependencyFactory
    {
        $config = new ConfigurationArray([
            'migrations_paths' => [
                'DoctrineMigrations' => $this->projectDir . '/migrations',
            ],
            'transactional' => false,
            'all_or_nothing' => false,
        ]);

        return DependencyFactory::fromConnection(
            $config,
            new ExistingConnection($connection),
        );
    }
}
