<?php

declare(strict_types=1);

namespace App\Service;

use App\Update\UpdateManifest;
use App\Update\UpdateResult;
use App\Update\UpdateStatus;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebinterfaceUpdateService
{
    private const DEFAULT_EXCLUDES = [
        '.env',
        'config/local*',
        'var/',
        'storage/',
        'uploads/',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $manifestUrl,
        private readonly string $installDir,
        private readonly string $releasesDir,
        private readonly string $currentSymlink,
        private readonly string $lockFile,
        private readonly string $excludes,
        private readonly string $fallbackVersion,
    ) {
    }

    public function checkForUpdate(): UpdateStatus
    {
        $installedVersion = $this->getInstalledVersion();
        $manifestResult = $this->fetchManifest();
        if ($manifestResult['manifest'] === null) {
            return new UpdateStatus(
                $installedVersion,
                null,
                null,
                null,
                $manifestResult['error'],
                null,
            );
        }

        $manifest = $manifestResult['manifest'];
        $updateAvailable = $this->isUpdateAvailable($installedVersion, $manifest->latest);

        return new UpdateStatus(
            $installedVersion,
            $manifest->latest,
            $updateAvailable,
            $manifest->notes,
            null,
            $manifest->assetUrl,
        );
    }

    public function applyUpdate(): UpdateResult
    {
        $logPath = $this->resolveLogPath($this->installDir);
        $lockHandle = $this->acquireLock();
        if ($lockHandle === null) {
            return new UpdateResult(
                false,
                'Update läuft bereits.',
                'Update-Lock aktiv.',
                $logPath,
                $this->getInstalledVersion(),
                null,
            );
        }

        try {
            $this->log($logPath, 'Starte Update.');
            $manifestResult = $this->fetchManifest();
            if ($manifestResult['manifest'] === null) {
                $this->log($logPath, 'Manifest konnte nicht geladen werden.');
                return new UpdateResult(
                    false,
                    'Manifest konnte nicht geladen werden.',
                    $manifestResult['error'],
                    $logPath,
                    $this->getInstalledVersion(),
                    null,
                );
            }

            $manifest = $manifestResult['manifest'];
            $installedVersion = $this->getInstalledVersion();
            $updateAvailable = $this->isUpdateAvailable($installedVersion, $manifest->latest);
            if ($updateAvailable === false) {
                $this->log($logPath, 'Kein Update verfügbar.');
                return new UpdateResult(
                    true,
                    'Kein Update verfügbar.',
                    null,
                    $logPath,
                    $installedVersion,
                    $manifest->latest,
                );
            }

            $workDir = $this->createWorkDir();
            $archivePath = $this->downloadAsset($manifest->assetUrl, $workDir, $logPath);
            if ($archivePath === null) {
                return new UpdateResult(
                    false,
                    'Download fehlgeschlagen.',
                    'Asset download fehlgeschlagen.',
                    $logPath,
                    $installedVersion,
                    $manifest->latest,
                );
            }

            if ($manifest->sha256 !== null && !$this->verifySha256($archivePath, $manifest->sha256, $logPath)) {
                return new UpdateResult(
                    false,
                    'SHA256-Prüfung fehlgeschlagen.',
                    'SHA256 mismatch.',
                    $logPath,
                    $installedVersion,
                    $manifest->latest,
                );
            }

            $stagingDir = $this->extractArchive($archivePath, $workDir, $logPath);
            if ($stagingDir === null) {
                return new UpdateResult(
                    false,
                    'Entpacken fehlgeschlagen.',
                    'Archive konnte nicht entpackt werden.',
                    $logPath,
                    $installedVersion,
                    $manifest->latest,
                );
            }

            $extractedRoot = $this->resolveExtractedRoot($stagingDir);
            $updateApplied = $this->deployUpdate($extractedRoot, $manifest->latest, $logPath);
            if (!$updateApplied) {
                return new UpdateResult(
                    false,
                    'Update fehlgeschlagen.',
                    'Deployment fehlgeschlagen.',
                    $logPath,
                    $installedVersion,
                    $manifest->latest,
                );
            }

            return new UpdateResult(
                true,
                'Update erfolgreich installiert.',
                null,
                $logPath,
                $manifest->latest,
                $manifest->latest,
            );
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    private function fetchManifest(): array
    {
        if (trim($this->manifestUrl) === '') {
            return ['manifest' => null, 'error' => 'Update-Manifest ist nicht konfiguriert.'];
        }

        try {
            $response = $this->httpClient->request('GET', $this->manifestUrl, [
                'timeout' => 6,
            ]);
            if ($response->getStatusCode() !== 200) {
                return ['manifest' => null, 'error' => 'Manifest nicht erreichbar.'];
            }
            $payload = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return ['manifest' => null, 'error' => 'Manifest konnte nicht gelesen werden.'];
        }

        if (!is_array($payload)) {
            return ['manifest' => null, 'error' => 'Manifest ist ungültig.'];
        }

        $latest = is_string($payload['latest'] ?? null) ? trim($payload['latest']) : '';
        $assetUrl = is_string($payload['asset_url'] ?? null) ? trim($payload['asset_url']) : '';
        $sha256 = is_string($payload['sha256'] ?? null) ? trim($payload['sha256']) : null;
        $notes = is_string($payload['notes'] ?? null) ? trim($payload['notes']) : null;

        if ($latest === '' || $assetUrl === '') {
            return ['manifest' => null, 'error' => 'Manifest ist unvollständig.'];
        }

        return [
            'manifest' => new UpdateManifest($latest, $assetUrl, $sha256 !== '' ? $sha256 : null, $notes !== '' ? $notes : null),
            'error' => null,
        ];
    }

    private function isUpdateAvailable(?string $installedVersion, ?string $latestVersion): ?bool
    {
        $installed = $this->normalizeVersion($installedVersion);
        $latest = $this->normalizeVersion($latestVersion);

        if ($installed === null || $latest === null) {
            return null;
        }

        return version_compare($installed, $latest, '<');
    }

    private function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $value = trim($version);
        if ($value === '') {
            return null;
        }

        $value = ltrim($value, 'vV');
        $value = preg_replace('/[^0-9A-Za-z.+-]/', '', $value) ?? $value;

        return $value !== '' ? $value : null;
    }

    private function getInstalledVersion(): ?string
    {
        $versionFile = rtrim($this->installDir, '/\\') . '/VERSION';
        $version = $this->readVersionFile($versionFile);
        if ($version !== null) {
            return $version;
        }

        if ($this->currentSymlink !== '' && is_link($this->currentSymlink)) {
            $target = readlink($this->currentSymlink);
            if ($target !== false) {
                $version = $this->readVersionFile(rtrim($target, '/\\') . '/VERSION');
                if ($version !== null) {
                    return $version;
                }
            }
        }

        return $this->fallbackVersion !== '' ? $this->fallbackVersion : null;
    }

    private function readVersionFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));
        return $contents !== '' ? $contents : null;
    }

    private function acquireLock(): mixed
    {
        $handle = @fopen($this->lockFile, 'c+');
        if (!is_resource($handle)) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function releaseLock(mixed $handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function createWorkDir(): string
    {
        $workDir = rtrim(sys_get_temp_dir(), '/\\') . '/webinterface_update_' . bin2hex(random_bytes(6));
        if (!is_dir($workDir)) {
            mkdir($workDir, 0770, true);
        }

        return $workDir;
    }

    private function downloadAsset(string $assetUrl, string $workDir, string $logPath): ?string
    {
        $path = parse_url($assetUrl, PHP_URL_PATH);
        $filename = is_string($path) && $path !== '' ? basename($path) : 'package';
        $archivePath = $workDir . '/' . $filename;

        try {
            $response = $this->httpClient->request('GET', $assetUrl, [
                'timeout' => 30,
            ]);
            if ($response->getStatusCode() !== 200) {
                $this->log($logPath, 'Asset download fehlgeschlagen: HTTP ' . $response->getStatusCode());
                return null;
            }
            file_put_contents($archivePath, $response->getContent(false));
        } catch (\Throwable $exception) {
            $this->log($logPath, 'Asset download fehlgeschlagen.');
            return null;
        }

        return $archivePath;
    }

    private function verifySha256(string $archivePath, string $expected, string $logPath): bool
    {
        $actual = hash_file('sha256', $archivePath);
        if ($actual === false) {
            $this->log($logPath, 'SHA256 konnte nicht berechnet werden.');
            return false;
        }

        if (strtolower($actual) !== strtolower($expected)) {
            $this->log($logPath, sprintf('SHA256 mismatch: expected %s got %s', $expected, $actual));
            return false;
        }

        $this->log($logPath, 'SHA256 erfolgreich verifiziert.');
        return true;
    }

    private function extractArchive(string $archivePath, string $workDir, string $logPath): ?string
    {
        $stagingDir = $workDir . '/staging';
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0770, true);
        }

        $lower = strtolower($archivePath);

        try {
            if (str_ends_with($lower, '.zip')) {
                $zip = new \ZipArchive();
                if ($zip->open($archivePath) !== true) {
                    $this->log($logPath, 'ZIP-Archiv konnte nicht geöffnet werden.');
                    return null;
                }
                $zip->extractTo($stagingDir);
                $zip->close();
                return $stagingDir;
            }

            if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz')) {
                $phar = new \PharData($archivePath);
                $tarPath = $archivePath;
                if (str_ends_with($lower, '.gz')) {
                    $tarPath = substr($archivePath, 0, -3);
                    if (!file_exists($tarPath)) {
                        $phar->decompress();
                    }
                    $phar = new \PharData($tarPath);
                }
                $phar->extractTo($stagingDir, null, true);
                return $stagingDir;
            }

            if (str_ends_with($lower, '.tar')) {
                $phar = new \PharData($archivePath);
                $phar->extractTo($stagingDir, null, true);
                return $stagingDir;
            }
        } catch (\Throwable) {
            $this->log($logPath, 'Archiv konnte nicht entpackt werden.');
            return null;
        }

        $this->log($logPath, 'Unbekanntes Archivformat.');
        return null;
    }

    private function resolveExtractedRoot(string $stagingDir): string
    {
        $entries = array_values(array_filter(scandir($stagingDir) ?: [], static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..';
        }));

        if (count($entries) === 1) {
            $candidate = $stagingDir . '/' . $entries[0];
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $stagingDir;
    }

    private function deployUpdate(string $sourceDir, string $version, string $logPath): bool
    {
        $excludes = $this->parseExcludes();
        if ($this->canUseSymlinkStrategy()) {
            return $this->deployWithSymlink($sourceDir, $version, $logPath, $excludes);
        }

        return $this->deployInPlace($sourceDir, $version, $logPath, $excludes);
    }

    private function canUseSymlinkStrategy(): bool
    {
        if ($this->releasesDir === '' || $this->currentSymlink === '') {
            return false;
        }

        $releasesParent = dirname($this->releasesDir);
        if (!is_dir($this->releasesDir) && !is_writable($releasesParent)) {
            return false;
        }

        $currentParent = dirname($this->currentSymlink);
        if (!is_dir($currentParent) || !is_writable($currentParent)) {
            return false;
        }

        return true;
    }

    private function deployWithSymlink(string $sourceDir, string $version, string $logPath, array $excludes): bool
    {
        $releaseDir = rtrim($this->releasesDir, '/\\') . '/' . $version;
        if (!is_dir($this->releasesDir)) {
            mkdir($this->releasesDir, 0775, true);
        }

        if (is_dir($releaseDir)) {
            $this->removeDirectory($releaseDir);
        }

        $this->copyDirectory($sourceDir, $releaseDir, []);
        $currentDir = $this->resolveCurrentDir();
        if ($currentDir !== null) {
            $this->copyExcludedPaths($currentDir, $releaseDir, $excludes);
        }

        $this->writeVersionFile($releaseDir, $version);
        if (!$this->runPostDeploy($releaseDir, $logPath)) {
            $this->log($logPath, 'Post-Deploy fehlgeschlagen. Rollback durchgeführt.');
            $this->removeDirectory($releaseDir);
            return false;
        }

        if (is_link($this->currentSymlink) || file_exists($this->currentSymlink)) {
            unlink($this->currentSymlink);
        }

        symlink($releaseDir, $this->currentSymlink);
        $this->log($logPath, 'Symlink auf neue Version gesetzt: ' . $releaseDir);

        return true;
    }

    private function deployInPlace(string $sourceDir, string $version, string $logPath, array $excludes): bool
    {
        if (!$this->commandExists('rsync')) {
            $this->log($logPath, 'rsync ist erforderlich für In-Place-Updates.');
            return false;
        }

        $backupDir = rtrim(sys_get_temp_dir(), '/\\') . '/webinterface_backup_' . bin2hex(random_bytes(6));
        mkdir($backupDir, 0770, true);

        if (!$this->runRsync($this->installDir, $backupDir, $excludes, $logPath)) {
            $this->log($logPath, 'Backup konnte nicht erstellt werden.');
            return false;
        }

        if (!$this->runRsync($sourceDir, $this->installDir, $excludes, $logPath, true)) {
            $this->log($logPath, 'Deploy fehlgeschlagen. Rollback wird durchgeführt.');
            $this->runRsync($backupDir, $this->installDir, [], $logPath, true);
            return false;
        }

        $this->writeVersionFile($this->installDir, $version);
        if (!$this->runPostDeploy($this->installDir, $logPath)) {
            $this->log($logPath, 'Post-Deploy fehlgeschlagen. Rollback wird durchgeführt.');
            $this->runRsync($backupDir, $this->installDir, [], $logPath, true);
            return false;
        }

        return true;
    }

    private function runPostDeploy(string $baseDir, string $logPath): bool
    {
        $appRoot = $this->resolveAppRoot($baseDir);

        if ($this->commandExists('composer') && is_file($appRoot . '/composer.json')) {
            $result = $this->runCommand('composer install --no-dev --optimize-autoloader --no-interaction', $appRoot);
            $this->logCommandResult($logPath, 'composer install', $result);
            if ($result['exitCode'] !== 0) {
                return false;
            }
        } else {
            $this->log($logPath, 'Composer nicht gefunden oder composer.json fehlt. Schritt übersprungen.');
        }

        if (!$this->commandExists('php')) {
            $this->log($logPath, 'PHP nicht gefunden. Migrationen/Cache werden übersprungen.');
            return true;
        }

        $consolePath = $appRoot . '/bin/console';
        if (!is_file($consolePath)) {
            $this->log($logPath, 'bin/console nicht gefunden. Migrationen/Cache werden übersprungen.');
            return true;
        }

        $migrate = $this->runCommand('php bin/console doctrine:migrations:migrate --no-interaction', $appRoot);
        $this->logCommandResult($logPath, 'doctrine:migrations:migrate', $migrate);
        if ($migrate['exitCode'] !== 0) {
            return false;
        }

        $schema = $this->runCommand('php bin/console doctrine:schema:validate --no-interaction', $appRoot);
        $this->logCommandResult($logPath, 'doctrine:schema:validate', $schema, true);

        $cache = $this->runCommand('php bin/console cache:clear', $appRoot);
        $this->logCommandResult($logPath, 'cache:clear', $cache);
        if ($cache['exitCode'] !== 0) {
            return false;
        }

        return true;
    }

    private function resolveAppRoot(string $baseDir): string
    {
        $coreDir = rtrim($baseDir, '/\\') . '/core';
        return is_dir($coreDir) ? $coreDir : $baseDir;
    }

    private function resolveCurrentDir(): ?string
    {
        if (is_link($this->currentSymlink)) {
            $target = readlink($this->currentSymlink);
            if ($target !== false) {
                return $target;
            }
        }

        if (is_dir($this->installDir)) {
            return $this->installDir;
        }

        return null;
    }

    private function parseExcludes(): array
    {
        $raw = array_map('trim', explode(',', $this->excludes));
        $filtered = array_values(array_filter($raw, static fn (string $value): bool => $value !== ''));

        return $filtered !== [] ? $filtered : self::DEFAULT_EXCLUDES;
    }

    private function copyExcludedPaths(string $sourceDir, string $targetDir, array $excludes): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relativePath = ltrim(str_replace('\\', '/', $iterator->getSubPathname()), '/');
            if (!$this->matchesExclude($relativePath, $excludes)) {
                continue;
            }

            $destination = rtrim($targetDir, '/\\') . '/' . $relativePath;
            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0775, true);
                }
            } else {
                if (!is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0775, true);
                }
                copy($item->getPathname(), $destination);
            }
        }
    }

    private function matchesExclude(string $relativePath, array $excludes): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        foreach ($excludes as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern));
            if ($pattern === '') {
                continue;
            }
            $isDirPattern = str_ends_with($pattern, '/');
            $pattern = ltrim(rtrim($pattern, '/'), '/');
            if ($pattern === '') {
                continue;
            }
            if (fnmatch($pattern, $normalized)) {
                return true;
            }
            if ($isDirPattern && str_starts_with($normalized, $pattern . '/')) {
                return true;
            }
        }

        return false;
    }

    private function copyDirectory(string $sourceDir, string $targetDir, array $excludes): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relativePath = ltrim(str_replace('\\', '/', $iterator->getSubPathname()), '/');
            if ($this->matchesExclude($relativePath, $excludes)) {
                continue;
            }

            $destination = rtrim($targetDir, '/\\') . '/' . $relativePath;
            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0775, true);
                }
            } else {
                if (!is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0775, true);
                }
                copy($item->getPathname(), $destination);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }

    private function writeVersionFile(string $baseDir, string $version): void
    {
        $versionFile = rtrim($baseDir, '/\\') . '/VERSION';
        file_put_contents($versionFile, $version . PHP_EOL);
    }

    private function resolveLogPath(string $baseDir): string
    {
        $appRoot = $this->resolveAppRoot($baseDir);
        $logDir = rtrim($appRoot, '/\\') . '/var/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        return $logDir . '/update.log';
    }

    private function log(string $logPath, string $message): void
    {
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        file_put_contents($logPath, sprintf("[%s] %s\n", $timestamp, $message), FILE_APPEND);
    }

    private function commandExists(string $command): bool
    {
        $result = trim((string) shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command))));
        return $result !== '';
    }

    private function runCommand(string $command, string $cwd): array
    {
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['exitCode' => 1, 'output' => 'Process konnte nicht gestartet werden.'];
        }

        $output = '';
        foreach ([1, 2] as $index) {
            $output .= stream_get_contents($pipes[$index]);
            fclose($pipes[$index]);
        }

        $exitCode = proc_close($process);

        return ['exitCode' => $exitCode, 'output' => trim($output)];
    }

    private function logCommandResult(string $logPath, string $label, array $result, bool $warnOnly = false): void
    {
        $summary = sprintf('%s exit code: %s', $label, (string) $result['exitCode']);
        $this->log($logPath, $summary);
        if ($result['output'] !== '') {
            $this->log($logPath, $label . ' output: ' . substr($result['output'], 0, 2000));
        }
        if ($warnOnly && $result['exitCode'] !== 0) {
            $this->log($logPath, $label . ' meldet Warnungen.');
        }
    }

    private function runRsync(string $source, string $destination, array $excludes, string $logPath, bool $delete = false): bool
    {
        $args = ['-a'];
        if ($delete) {
            $args[] = '--delete';
        }
        foreach ($excludes as $exclude) {
            $args[] = '--exclude=' . escapeshellarg($exclude);
        }

        $command = sprintf(
            'rsync %s %s %s',
            implode(' ', $args),
            escapeshellarg(rtrim($source, '/\\') . '/'),
            escapeshellarg(rtrim($destination, '/\\') . '/'),
        );

        $result = $this->runCommand($command, $this->installDir);
        $this->logCommandResult($logPath, 'rsync', $result);

        return $result['exitCode'] === 0;
    }
}
