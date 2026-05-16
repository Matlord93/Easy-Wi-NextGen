<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Module\Core\Application\CoreReleaseChecker;
use App\Module\Core\Application\PanelUpdateNewsPublisher;
use App\Module\Core\Update\UpdateManifest;
use App\Module\Core\Update\UpdateResult;
use App\Module\Core\Update\UpdateStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebinterfaceUpdateService
{
    private const RUNTIME_MAINTENANCE_FILE = 'var/update-maintenance.flag';

    private const DEFAULT_EXCLUDES = [
        '.env',
        'config/local*',
        'var/',
        'srv/',
        'storage/',
        'uploads/',
    ];

    /**
     * Keep Composer dependencies available while the currently running console process
     * finishes an in-place update, especially when release artifacts omit vendor/.
     */
    private const REQUIRED_RUNTIME_EXCLUDES = [
        'vendor/',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly WebinterfaceUpdateSettingsService $settingsService,
        private readonly string $manifestUrl,
        private readonly string $installDir,
        private readonly string $releasesDir,
        private readonly string $currentSymlink,
        private readonly string $lockFile,
        private readonly string $excludes,
        private readonly string $fallbackVersion,
        private readonly string $releaseRepository,
        private readonly string $releaseChannel,
        private readonly string $kernelEnvironment,
        private readonly bool $kernelDebug,
        private readonly ?PanelUpdateNewsPublisher $panelUpdateNewsPublisher = null,
        private readonly ?CoreReleaseChecker $coreReleaseChecker = null,
        private readonly ?string $githubToken = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function checkForUpdate(bool $force = false): UpdateStatus
    {
        $installedVersion = $this->getInstalledVersion();
        $manifestResult = $this->fetchManifest($force);
        if ($manifestResult['manifest'] === null) {
            return new UpdateStatus(
                $installedVersion,
                null,
                null,
                null,
                $manifestResult['error'],
                null,
                null,
                $manifestResult['cache_status'] ?? [],
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
            $manifest->sha256,
            $manifestResult['cache_status'] ?? [],
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

            $useDelta = $this->shouldUseDelta($installedVersion, $manifest, $logPath);
            $assetUrl = $useDelta ? $manifest->deltaAssetUrl : $manifest->assetUrl;
            if ($assetUrl === null || $assetUrl === '') {
                $this->log($logPath, 'Update-Asset ist nicht verfügbar.');
                return new UpdateResult(
                    false,
                    'Update-Asset fehlt.',
                    'Asset URL fehlt.',
                    $logPath,
                    $installedVersion,
                    $manifest->latest,
                );
            }

            $workDir = $this->createWorkDir();
            $archivePath = $this->downloadAsset($assetUrl, $workDir, $logPath, $useDelta ? null : $manifest->assetName);
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

            $expectedSha = $useDelta ? $manifest->deltaSha256 : $manifest->sha256;
            if ($expectedSha === null && !$useDelta && $manifest->checksumsUrl !== null) {
                $expectedSha = $this->fetchChecksumForAsset($manifest->checksumsUrl, $manifest->assetName, $logPath);
            }
            if ($expectedSha === null) {
                $this->log($logPath, 'SHA256-Prüfsumme fehlt. Update wird abgebrochen.');
                return new UpdateResult(
                    false,
                    'SHA256-Prüfsumme fehlt.',
                    'Checksums Asset fehlt oder enthält keinen passenden Eintrag.',
                    $logPath,
                    $installedVersion,
                    $manifest->latest,
                );
            }
            if (!$this->verifySha256($archivePath, $expectedSha, $logPath)) {
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
                    'Archiv konnte nicht entpackt werden.',
                    $logPath,
                    $installedVersion,
                    $manifest->latest,
                );
            }

            $extractedRoot = $this->resolveExtractedRoot($stagingDir);
            if ($useDelta) {
                $updateApplied = $this->deployDeltaUpdate($extractedRoot, $manifest->latest, $logPath, $manifest->deltaDeletes);
            } else {
                $updateApplied = $this->deployUpdate($extractedRoot, $manifest->latest, $logPath);
            }
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

            $this->panelUpdateNewsPublisher?->publishSuccessfulUpdate($installedVersion, $manifest->latest, $manifest->notes);

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

    public function applyMigrations(): UpdateResult
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
            $this->log($logPath, 'Starte Datenbank-Migrationen.');
            $currentDir = $this->resolveCurrentDir();
            if ($currentDir === null) {
                $this->log($logPath, 'Installationsverzeichnis nicht gefunden.');
                return new UpdateResult(
                    false,
                    'Installationsverzeichnis nicht gefunden.',
                    'Kein gültiges Installationsverzeichnis gefunden.',
                    $logPath,
                    $this->getInstalledVersion(),
                    null,
                );
            }

            $appRoot = $this->resolveAppRoot($currentDir);
            if (!is_file(PHP_BINARY) && !$this->commandExists(PHP_BINARY)) {
                $this->log($logPath, 'PHP-Binary nicht gefunden. Migrationen können nicht ausgeführt werden: ' . PHP_BINARY);
                return new UpdateResult(
                    false,
                    'PHP nicht gefunden.',
                    'PHP CLI ist nicht verfügbar.',
                    $logPath,
                    $this->getInstalledVersion(),
                    null,
                );
            }

            if (!is_file($appRoot . '/bin/console')) {
                $this->log($logPath, 'bin/console nicht gefunden. Migrationen können nicht ausgeführt werden.');
                return new UpdateResult(
                    false,
                    'bin/console nicht gefunden.',
                    'Symfony Console fehlt im Installationsverzeichnis.',
                    $logPath,
                    $this->getInstalledVersion(),
                    null,
                );
            }

            $migrate = $this->runCommand($this->buildConsoleCommand('doctrine:migrations:migrate', ['--no-interaction']), $appRoot);
            $this->logCommandResult($logPath, 'doctrine:migrations:migrate', $migrate);
            if ($migrate['exitCode'] !== 0) {
                return new UpdateResult(
                    false,
                    'Migrationen fehlgeschlagen.',
                    $this->formatCommandFailure('doctrine:migrations:migrate', $migrate),
                    $logPath,
                    $this->getInstalledVersion(),
                    null,
                );
            }

            $schema = $this->runCommand($this->buildConsoleCommand('doctrine:schema:validate', ['--no-interaction']), $appRoot);
            $this->logCommandResult($logPath, 'doctrine:schema:validate', $schema);
            if ($schema['exitCode'] !== 0) {
                return new UpdateResult(
                    false,
                    'Schema-Validierung fehlgeschlagen.',
                    $this->formatCommandFailure('doctrine:schema:validate', $schema),
                    $logPath,
                    $this->getInstalledVersion(),
                    null,
                );
            }

            $seedSettings = $this->runCommand($this->buildConsoleCommand('app:settings:ensure-defaults', ['--no-interaction']), $appRoot);
            $this->logCommandResult($logPath, 'app:settings:ensure-defaults', $seedSettings);
            if ($seedSettings['exitCode'] !== 0) {
                return new UpdateResult(
                    false,
                    'Settings-Defaults konnten nicht sichergestellt werden.',
                    $this->formatCommandFailure('app:settings:ensure-defaults', $seedSettings),
                    $logPath,
                    $this->getInstalledVersion(),
                    null,
                );
            }

            $this->logMissingEnvSecretsHint($appRoot, $logPath);

            $cache = $this->runCommand($this->buildConsoleCommand('cache:clear'), $appRoot);
            $this->logCommandResult($logPath, 'cache:clear', $cache);
            if ($cache['exitCode'] !== 0) {
                return new UpdateResult(
                    false,
                    'Cache konnte nicht geleert werden.',
                    $this->formatCommandFailure('cache:clear', $cache),
                    $logPath,
                    $this->getInstalledVersion(),
                    null,
                );
            }

            return new UpdateResult(
                true,
                'Datenbank-Update abgeschlossen.',
                null,
                $logPath,
                $this->getInstalledVersion(),
                null,
            );
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    private function fetchManifest(bool $force = false): array
    {
        $manifest = $this->fetchManifestFromGithubRelease($force);
        if ($manifest !== null) {
            return ['manifest' => $manifest, 'error' => null, 'cache_status' => $this->githubReleaseCacheStatus()];
        }

        if (trim($this->manifestUrl) !== '') {
            $manifest = $this->fetchManifestFromUrl($this->manifestUrl);
            if ($manifest !== null) {
                return ['manifest' => $manifest, 'error' => null, 'cache_status' => $this->githubReleaseCacheStatus()];
            }
        }

        $channel = $this->normalizeReleaseChannel($this->settingsService->getCoreChannel());
        $details = $this->coreReleaseChecker !== null
            ? $this->coreReleaseChecker->describeReleasePackageSelectionFailure($channel)
            : sprintf('Kein gültiges Core-Paket im Repository %s gefunden.', $this->releaseRepository !== '' ? $this->releaseRepository : '(nicht konfiguriert)');

        return ['manifest' => null, 'error' => sprintf(
            '%s Channel: %s. Erwartet: %s.',
            $details,
            $channel,
            implode(', ', CoreReleaseChecker::allowedCoreAssetPatterns()),
        ), 'cache_status' => $this->githubReleaseCacheStatus()];
    }

    /** @return array<string, mixed> */
    public function getUpdateCheckCacheStatus(): array
    {
        return $this->githubReleaseCacheStatus();
    }

    /** @return array<string, mixed> */
    private function githubReleaseCacheStatus(): array
    {
        if ($this->coreReleaseChecker === null) {
            return [];
        }

        return $this->coreReleaseChecker->getCacheStatus($this->normalizeReleaseChannel($this->settingsService->getCoreChannel()));
    }

    private function fetchManifestFromUrl(string $url): ?UpdateManifest
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 10]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $payload = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $manifest = $this->createManifestFromPayload($payload);
        if ($manifest === null || !$this->isAllowedCoreAssetUrl($manifest->assetUrl) || !$this->isManifestAllowedForSelectedChannel($manifest) || !$this->isAllowedManifestCoreAsset($manifest)) {
            return null;
        }
        if ($manifest->deltaAssetUrl !== null && !$this->isAllowedCoreAssetUrl($manifest->deltaAssetUrl)) {
            return null;
        }

        return $manifest;
    }

    private function fetchManifestFromGithubRelease(bool $force = false): ?UpdateManifest
    {
        if ($this->coreReleaseChecker === null) {
            return null;
        }

        $channel = $this->normalizeReleaseChannel($this->settingsService->getCoreChannel());
        $package = $this->coreReleaseChecker->getReleasePackageForChannel($channel, null, $force);
        if ($package === null) {
            return null;
        }

        $version = is_string($package['version'] ?? null) ? trim($package['version']) : '';
        $assetUrl = is_string($package['download_url'] ?? null) ? trim($package['download_url']) : '';
        $checksumsUrl = is_string($package['checksums_url'] ?? null) ? trim($package['checksums_url']) : '';
        $assetName = is_string($package['asset_name'] ?? null) ? trim($package['asset_name']) : 'easywi-core.tar.gz';
        if ($version === '' || $assetUrl === '' || $checksumsUrl === '') {
            return null;
        }

        return new UpdateManifest(
            $version,
            $assetUrl,
            null,
            null,
            null,
            null,
            null,
            [],
            $checksumsUrl,
            is_string($package['signature_url'] ?? null) ? $package['signature_url'] : null,
            $assetName,
            $channel,
        );
    }

    private function createManifestFromPayload(mixed $payload): ?UpdateManifest
    {
        if (!is_array($payload)) {
            return null;
        }

        $channel = $this->normalizeReleaseChannel($this->settingsService->getCoreChannel());
        $latestPayload = $payload['latest'] ?? null;
        $latest = '';
        if (is_array($latestPayload)) {
            $latest = is_string($latestPayload[$channel] ?? null) ? trim($latestPayload[$channel]) : '';
        } elseif (is_string($latestPayload)) {
            $latest = trim($latestPayload);
        }
        if ($latest === '' && is_string($payload['version'] ?? null)) {
            $latest = trim($payload['version']);
        }
        if ($latest === '') {
            return null;
        }

        // New structured feed format: {latest:{stable,beta,dev}, releases:[{version, channel, artifacts:{core_novendor_targz:{url,sha256}}, changelog}]}
        if (is_array($payload['releases'] ?? null)) {
            return $this->createManifestFromReleaseFeed($latest, $payload['releases'], $channel);
        }

        // Legacy flat format: {latest, asset_url, sha256, notes, delta}
        $assetUrl = is_string($payload['asset_url'] ?? null) ? trim($payload['asset_url']) : '';
        if ($assetUrl === '' && is_string($payload['download_url'] ?? null)) {
            $assetUrl = trim($payload['download_url']);
        }
        $sha256 = is_string($payload['sha256'] ?? null) ? trim($payload['sha256']) : null;
        $notes = is_string($payload['notes'] ?? null) ? trim($payload['notes']) : null;
        $checksumsUrl = is_string($payload['checksums_url'] ?? null) ? trim($payload['checksums_url']) : null;
        $signatureUrl = is_string($payload['signature_url'] ?? null) ? trim($payload['signature_url']) : null;
        $assetName = is_string($payload['asset_name'] ?? null) ? trim($payload['asset_name']) : $this->assetNameFromUrl($assetUrl);
        $manifestChannel = is_string($payload['channel'] ?? null) ? $this->normalizeReleaseChannel($payload['channel']) : $channel;
        $deltaPayload = is_array($payload['delta'] ?? null) ? $payload['delta'] : null;
        $deltaFrom = is_array($deltaPayload) && is_string($deltaPayload['from'] ?? null) ? trim($deltaPayload['from']) : null;
        $deltaAssetUrl = is_array($deltaPayload) && is_string($deltaPayload['asset_url'] ?? null) ? trim($deltaPayload['asset_url']) : null;
        $deltaSha256 = is_array($deltaPayload) && is_string($deltaPayload['sha256'] ?? null) ? trim($deltaPayload['sha256']) : null;
        $deltaDeletes = [];
        if (is_array($deltaPayload) && is_array($deltaPayload['delete'] ?? null)) {
            $deltaDeletes = array_values(array_filter(array_map(static function ($value): ?string {
                return is_string($value) ? trim($value) : null;
            }, $deltaPayload['delete']), static fn (?string $value): bool => $value !== null && $value !== ''));
        }

        if ($assetUrl === '') {
            return null;
        }

        return new UpdateManifest(
            $latest,
            $assetUrl,
            $sha256 !== '' ? $sha256 : null,
            $notes !== '' ? $notes : null,
            $deltaFrom !== '' ? $deltaFrom : null,
            $deltaAssetUrl !== '' ? $deltaAssetUrl : null,
            $deltaSha256 !== '' ? $deltaSha256 : null,
            $deltaDeletes,
            $checksumsUrl !== '' ? $checksumsUrl : null,
            $signatureUrl !== '' ? $signatureUrl : null,
            $assetName !== '' ? $assetName : null,
            $manifestChannel,
        );
    }


    /**
     * @param array<string, mixed> $artifacts
     * @return array<string, mixed>|null
     */
    private function selectReleaseFeedCoreArtifact(array $artifacts): ?array
    {
        foreach (['webinterface_zip', 'core_zip', 'core_novendor_zip', 'core_novendor_targz'] as $key) {
            $artifact = $artifacts[$key] ?? null;
            if (is_array($artifact)) {
                return $artifact;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $releases
     */
    private function createManifestFromReleaseFeed(string $latest, array $releases, string $channel): ?UpdateManifest
    {
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $version = is_string($release['version'] ?? null) ? trim($release['version']) : '';
            $releaseChannel = is_string($release['channel'] ?? null) ? $this->normalizeReleaseChannel($release['channel']) : 'stable';
            if ($version !== $latest || $releaseChannel !== $channel) {
                continue;
            }

            $artifacts = is_array($release['artifacts'] ?? null) ? $release['artifacts'] : [];
            $artifact = $this->selectReleaseFeedCoreArtifact($artifacts);
            if ($artifact === null) {
                continue;
            }

            $assetUrl = is_string($artifact['url'] ?? null) ? trim($artifact['url']) : '';
            $sha256 = is_string($artifact['sha256'] ?? null) ? trim($artifact['sha256']) : null;
            $notes = is_string($release['changelog'] ?? null) ? trim($release['changelog']) : null;
            $checksumsUrl = is_string($artifact['checksums_url'] ?? null) ? trim($artifact['checksums_url']) : null;
            $assetName = is_string($artifact['asset_name'] ?? null) ? trim($artifact['asset_name']) : $this->assetNameFromUrl($assetUrl);

            if ($assetUrl === '') {
                continue;
            }

            return new UpdateManifest(
                $latest,
                $assetUrl,
                $sha256 !== '' ? $sha256 : null,
                $notes !== '' ? $notes : null,
                null,
                null,
                null,
                [],
                $checksumsUrl !== '' ? $checksumsUrl : null,
                null,
                $assetName !== '' ? $assetName : null,
                $channel,
            );
        }

        return null;
    }


    /**
     * @param array<string, mixed> $release
     */
    private function detectGithubReleaseChannel(array $release): string
    {
        foreach (['body', 'name'] as $field) {
            $value = $release[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            if (preg_match('/(?:^|\R)\s*(?:easywi[-_ ]?)?channel\s*[:=]\s*(stable|beta|dev|alpha)\b/i', $value, $matches) === 1) {
                return $this->normalizeReleaseChannel($matches[1]);
            }
        }

        $isPrerelease = ($release['prerelease'] ?? false) === true;
        if (!$isPrerelease) {
            return 'stable';
        }

        $tagLower = strtolower((string) ($release['tag_name'] ?? $release['name'] ?? ''));
        if (preg_match('/(?:^|[._\-+])(?:dev|alpha|snapshot|nightly)(?:$|[._\-+])/', $tagLower) === 1) {
            return 'dev';
        }
        if (preg_match('/(?:^|[._\-+])(?:beta|preview|rc)(?:$|[._\-+])/', $tagLower) === 1) {
            return 'beta';
        }

        return 'beta';
    }

    private function normalizeReleaseChannel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            'beta' => 'beta',
            'dev', 'alpha' => 'dev',
            default => 'stable',
        };
    }

    private function findAssetUrlByName(array $assets, string $assetName): ?string
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = is_string($asset['name'] ?? null) ? $asset['name'] : null;
            if ($name !== $assetName) {
                continue;
            }

            $url = is_string($asset['browser_download_url'] ?? null) ? trim($asset['browser_download_url']) : '';
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function findWebinterfaceArchiveAssetUrl(array $assets, string $version): ?string
    {
        $expected = 'easywi-webinterface-' . ltrim($version, 'vV') . '.zip';

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = is_string($asset['name'] ?? null) ? trim($asset['name']) : '';
            if ($name === '') {
                continue;
            }

            if ($name !== $expected && !preg_match('/^easywi-webinterface-[^\/]+\.zip$/i', $name)) {
                continue;
            }

            $url = is_string($asset['browser_download_url'] ?? null) ? trim($asset['browser_download_url']) : '';
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function assetNameFromUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $basename = basename($path);
        return $basename !== '' ? rawurldecode($basename) : null;
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

    private function downloadAsset(string $assetUrl, string $workDir, string $logPath, ?string $preferredFilename = null): ?string
    {
        $urlPath = parse_url($assetUrl, PHP_URL_PATH);
        $urlFilename = is_string($urlPath) && $urlPath !== '' ? rawurldecode(basename($urlPath)) : null;

        try {
            $response = $this->httpClient->request('GET', $assetUrl, [
                'timeout' => 30,
                'headers' => $this->downloadHeaders($assetUrl),
            ]);
            if ($response->getStatusCode() !== 200) {
                $this->log($logPath, 'Asset download fehlgeschlagen: HTTP ' . $response->getStatusCode());
                return null;
            }

            $headers = $response->getHeaders(false);
            $contentDisposition = $headers['content-disposition'][0] ?? null;
            $filename = $this->resolveDownloadedArchiveFilename(
                $preferredFilename,
                is_string($contentDisposition) ? $contentDisposition : null,
                $urlFilename,
            );
            $archivePath = $workDir . '/' . $filename;
            file_put_contents($archivePath, $response->getContent(false));
        } catch (\Throwable $exception) {
            $this->log($logPath, 'Asset download fehlgeschlagen.');
            return null;
        }

        return $archivePath;
    }

    private function resolveDownloadedArchiveFilename(?string $preferredFilename, ?string $contentDisposition, ?string $urlFilename): string
    {
        foreach ([
            $preferredFilename,
            $this->filenameFromContentDisposition($contentDisposition),
            $urlFilename,
        ] as $candidate) {
            $safe = $this->safeDownloadFilename($candidate);
            if ($safe !== null) {
                return $safe;
            }
        }

        return 'package';
    }

    private function filenameFromContentDisposition(?string $contentDisposition): ?string
    {
        if ($contentDisposition === null || trim($contentDisposition) === '') {
            return null;
        }

        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $contentDisposition, $matches) === 1) {
            return rawurldecode(trim($matches[1], " \""));
        }

        if (preg_match('/filename=(\"[^\"]+\"|[^;]+)/i', $contentDisposition, $matches) === 1) {
            return trim($matches[1], " \"");
        }

        return null;
    }

    private function safeDownloadFilename(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }

        $filename = trim(str_replace('\\', '/', $filename));
        if ($filename === '') {
            return null;
        }

        $basename = basename($filename);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return null;
        }

        return $basename;
    }


    private function fetchChecksumForAsset(string $checksumsUrl, ?string $assetName, string $logPath): ?string
    {
        $assetName = $assetName !== null && $assetName !== '' ? $assetName : 'easywi-core.tar.gz';

        try {
            $response = $this->httpClient->request('GET', $checksumsUrl, [
                'timeout' => 15,
                'headers' => $this->downloadHeaders($checksumsUrl),
            ]);
            if ($response->getStatusCode() !== 200) {
                $this->log($logPath, 'Checksums download fehlgeschlagen: HTTP ' . $response->getStatusCode());
                return null;
            }
            $contents = $response->getContent(false);
        } catch (\Throwable) {
            $this->log($logPath, 'Checksums download fehlgeschlagen.');
            return null;
        }

        foreach (preg_split('/\\r\\n|\\r|\\n/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', $line, $matches) !== 1) {
                continue;
            }
            if (basename(trim($matches[2])) === $assetName) {
                return strtolower($matches[1]);
            }
        }

        $this->log($logPath, sprintf('Checksums Datei enthält keinen Eintrag für %s.', $assetName));
        return null;
    }

    /** @return array<string, string> */
    private function downloadHeaders(string $url): array
    {
        $headers = [
            'User-Agent' => 'Easy-Wi-NextGen',
        ];

        $token = $this->resolveGithubToken();
        $host = parse_url($url, PHP_URL_HOST);
        $hostLower = is_string($host) ? strtolower($host) : '';
        if ($hostLower === 'api.github.com') {
            $headers['Accept'] = 'application/octet-stream';
        }
        if ($token !== '' && ($hostLower === 'github.com' || str_ends_with($hostLower, '.github.com') || $hostLower === 'api.github.com')) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    private function resolveGithubToken(): string
    {
        $token = $this->githubToken !== null ? trim($this->githubToken) : '';
        if ($token === '') {
            $token = trim((string) ($_SERVER['APP_GITHUB_TOKEN'] ?? $_ENV['APP_GITHUB_TOKEN'] ?? $_SERVER['GITHUB_TOKEN'] ?? $_ENV['GITHUB_TOKEN'] ?? ''));
        }

        return $token;
    }

    private function isAllowedManifestCoreAsset(UpdateManifest $manifest): bool
    {
        $assetName = $manifest->assetName ?? $this->assetNameFromUrl($manifest->assetUrl);
        if ($assetName === null) {
            return false;
        }

        $matcher = CoreReleaseChecker::coreAssetMatcher();
        return $matcher($assetName, $manifest->latest) !== false;
    }

    private function isManifestAllowedForSelectedChannel(UpdateManifest $manifest): bool
    {
        $channel = $this->normalizeReleaseChannel($this->settingsService->getCoreChannel());
        if ($manifest->channel !== null && $this->normalizeReleaseChannel($manifest->channel) !== $channel) {
            return false;
        }

        $versionChannel = $this->detectVersionChannel($manifest->latest);
        if ($versionChannel !== $channel) {
            return false;
        }

        $tag = $this->releaseTagFromGithubDownloadUrl($manifest->assetUrl);
        return $tag === null || $this->detectVersionChannel($tag) === $channel;
    }

    private function detectVersionChannel(string $version): string
    {
        $lower = strtolower($version);
        if (preg_match('/(?:^|[._\-+])(?:dev|alpha|snapshot|nightly)(?:$|[._\-+])/', $lower) === 1) {
            return 'dev';
        }
        if (preg_match('/(?:^|[._\-+])(?:beta|preview|rc)(?:$|[._\-+])/', $lower) === 1) {
            return 'beta';
        }

        return 'stable';
    }

    private function releaseTagFromGithubDownloadUrl(string $url): ?string
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'github.com') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/releases/download/([^/]+)/#', $path, $matches) !== 1) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    private function isAllowedCoreAssetUrl(string $url): bool
    {
        $repository = trim($this->releaseRepository, '/ ');
        if ($repository === '') {
            return false;
        }

        $parts = explode('/', $repository, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$owner, $repo] = array_map('rawurlencode', $parts);

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $normalizedPath = '/' . ltrim($path, '/');

        if ($host === 'github.com') {
            return str_starts_with($normalizedPath, sprintf('/%s/%s/releases/download/', $owner, $repo));
        }

        if ($host === 'api.github.com') {
            return str_starts_with($normalizedPath, sprintf('/repos/%s/%s/releases/assets/', $owner, $repo));
        }

        return false;
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

        $archiveType = $this->detectArchiveType($archivePath);

        try {
            if ($archiveType === 'zip') {
                if (!$this->extractZipArchive($archivePath, $stagingDir, $logPath)) {
                    return null;
                }
                $this->normalizeExtractedPermissions($stagingDir);
                return $stagingDir;
            }

            if ($archiveType === 'tar.gz') {
                if (!$this->extractTarArchive($archivePath, $stagingDir, true, $logPath)) {
                    return null;
                }
                $this->normalizeExtractedPermissions($stagingDir);
                return $stagingDir;
            }

            if ($archiveType === 'tar') {
                if (!$this->extractTarArchive($archivePath, $stagingDir, false, $logPath)) {
                    return null;
                }
                $this->normalizeExtractedPermissions($stagingDir);
                return $stagingDir;
            }
        } catch (\Throwable $exception) {
            $this->log($logPath, 'Archiv konnte nicht entpackt werden: ' . $exception->getMessage());
            return null;
        }

        $this->log($logPath, 'Unbekanntes Archivformat.');
        return null;
    }


    private function extractTarArchive(string $archivePath, string $stagingDir, bool $gzipCompressed, string $logPath): bool
    {
        try {
            $phar = $this->openTarArchive($archivePath, $gzipCompressed);
            if ($this->validatePharArchivePaths($phar, $logPath)) {
                $phar->extractTo($stagingDir, null, true);
                return true;
            }

            return false;
        } catch (\Throwable $exception) {
            $this->log($logPath, 'TAR-Archiv konnte nicht mit PharData entpackt werden: ' . $exception->getMessage());
        }

        return $this->extractTarArchiveWithCommand($archivePath, $stagingDir, $gzipCompressed, $logPath);
    }

    private function openTarArchive(string $archivePath, bool $gzipCompressed): \PharData
    {
        if (!$gzipCompressed) {
            return new \PharData($archivePath);
        }

        $gzipArchivePath = $archivePath;
        if (preg_match('/\.(?:tar\.gz|tgz)$/i', $gzipArchivePath) !== 1) {
            $gzipArchivePath = $archivePath . '.tar.gz';
            if (!file_exists($gzipArchivePath) && !copy($archivePath, $gzipArchivePath)) {
                throw new \RuntimeException('Temporäre .tar.gz-Datei konnte nicht erstellt werden.');
            }
        }

        $phar = new \PharData($gzipArchivePath);
        $tarPath = preg_replace('/\.(?:tar\.gz|tgz)$/i', '.tar', $gzipArchivePath);
        if (!is_string($tarPath) || $tarPath === '') {
            $tarPath = $gzipArchivePath . '.tar';
        }
        if (!file_exists($tarPath)) {
            $phar->decompress();
        }

        return new \PharData($tarPath);
    }

    private function extractTarArchiveWithCommand(string $archivePath, string $stagingDir, bool $gzipCompressed, string $logPath): bool
    {
        $listCommand = $gzipCompressed
            ? ['tar', '-tzf', $archivePath]
            : ['tar', '-tf', $archivePath];
        $listResult = $this->executeArchiveCommand($listCommand);
        if ($listResult['exitCode'] !== 0) {
            $this->log($logPath, 'TAR-Archiv konnte nicht mit tar geprüft werden: ' . $listResult['output']);
            return false;
        }

        foreach (preg_split('/\r\n|\r|\n/', $listResult['output']) ?: [] as $path) {
            if ($path === '') {
                continue;
            }
            if (!$this->isSafeArchivePath($path)) {
                $this->log($logPath, 'Archiv enthält einen unsicheren Pfad.');
                return false;
            }
        }

        $extractCommand = $gzipCompressed
            ? ['tar', '-xzf', $archivePath, '-C', $stagingDir]
            : ['tar', '-xf', $archivePath, '-C', $stagingDir];
        $extractResult = $this->executeArchiveCommand($extractCommand);
        if ($extractResult['exitCode'] !== 0) {
            $this->log($logPath, 'TAR-Archiv konnte nicht mit tar extrahiert werden: ' . $extractResult['output']);
            return false;
        }

        return true;
    }

    private function detectArchiveType(string $archivePath): ?string
    {
        $lower = strtolower($archivePath);
        if (str_ends_with($lower, '.zip')) {
            return 'zip';
        }
        if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz')) {
            return 'tar.gz';
        }
        if (str_ends_with($lower, '.tar')) {
            return 'tar';
        }

        if (is_file($archivePath) && filesize($archivePath) >= 4) {
            $handle = @fopen($archivePath, 'rb');
            if (is_resource($handle)) {
                $header = fread($handle, 512);
                fclose($handle);
                if (is_string($header)) {
                    if (str_starts_with($header, "PK\x03\x04") || str_starts_with($header, "PK\x05\x06") || str_starts_with($header, "PK\x07\x08")) {
                        return 'zip';
                    }
                    if (str_starts_with($header, "\x1F\x8B")) {
                        return 'tar.gz';
                    }
                    if (strlen($header) > 265 && substr($header, 257, 5) === 'ustar') {
                        return 'tar';
                    }
                }
            }
        }

        return null;
    }

    private function extractZipArchive(string $archivePath, string $stagingDir, string $logPath): bool
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($archivePath) !== true) {
                $this->log($logPath, 'ZIP-Archiv konnte nicht geöffnet werden; versuche Entpacken mit unzip.');
                return $this->extractZipArchiveWithUnzip($archivePath, $stagingDir, $logPath);
            }
            if (!$this->validateZipArchivePaths($zip, $logPath)) {
                $zip->close();
                return false;
            }
            $extracted = $zip->extractTo($stagingDir);
            $zip->close();
            if (!$extracted) {
                $this->log($logPath, 'ZIP-Archiv konnte nicht extrahiert werden; versuche Entpacken mit unzip.');
                return $this->extractZipArchiveWithUnzip($archivePath, $stagingDir, $logPath);
            }

            return true;
        }

        $this->log($logPath, 'PHP-ZIP-Erweiterung ist nicht verfügbar; versuche Entpacken mit unzip.');
        return $this->extractZipArchiveWithUnzip($archivePath, $stagingDir, $logPath);
    }

    private function extractZipArchiveWithUnzip(string $archivePath, string $stagingDir, string $logPath): bool
    {
        $listResult = $this->executeArchiveCommand(['unzip', '-Z', '-1', $archivePath]);
        if ($listResult['exitCode'] !== 0) {
            $this->log($logPath, 'ZIP-Archiv konnte nicht mit unzip geprüft werden: ' . $listResult['output']);
            return false;
        }

        foreach (preg_split('/\r\n|\r|\n/', $listResult['output']) ?: [] as $path) {
            if ($path === '') {
                continue;
            }
            if (!$this->isSafeArchivePath($path)) {
                $this->log($logPath, 'Archiv enthält einen unsicheren Pfad.');
                return false;
            }
        }

        $extractResult = $this->executeArchiveCommand(['unzip', '-qq', $archivePath, '-d', $stagingDir]);
        if ($extractResult['exitCode'] !== 0) {
            $this->log($logPath, 'ZIP-Archiv konnte nicht mit unzip extrahiert werden: ' . $extractResult['output']);
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $command
     * @return array{exitCode: int, output: string}
     */
    private function executeArchiveCommand(array $command): array
    {
        $process = @proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return [
                'exitCode' => 127,
                'output' => 'Prozess konnte nicht gestartet werden.',
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $output = trim((string) $stdout . PHP_EOL . (string) $stderr);

        return [
            'exitCode' => is_int($exitCode) ? $exitCode : 1,
            'output' => $output,
        ];
    }

    private function validateZipArchivePaths(\ZipArchive $zip, string $logPath): bool
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if ($name === false || !$this->isSafeArchivePath($name)) {
                $this->log($logPath, 'Archiv enthält einen unsicheren Pfad.');
                return false;
            }
        }

        return true;
    }

    private function validatePharArchivePaths(\PharData $phar, string $logPath): bool
    {
        foreach (new \RecursiveIteratorIterator($phar) as $entry) {
            $path = method_exists($entry, 'getRelativePathname') ? $entry->getRelativePathname() : $entry->getPathName();
            if (!$entry instanceof \SplFileInfo || !$this->isSafeArchivePath($path)) {
                $this->log($logPath, 'Archiv enthält einen unsicheren Pfad.');
                return false;
            }
        }

        return true;
    }

    private function isSafeArchivePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function normalizeExtractedPermissions(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            @chmod($item->getPathname(), $item->isDir() ? 0775 : 0664);
        }
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
        $initialInstall = $this->isInitialInstall();
        if (!$initialInstall) {
            $this->enableRuntimeMaintenance($this->installDir, $logPath);
        }

        try {
            return $this->deployInPlaceUnderMaintenance($sourceDir, $version, $logPath, $excludes, $initialInstall);
        } finally {
            if (!$initialInstall) {
                $this->disableRuntimeMaintenance($this->installDir, $logPath);
            }
        }
    }

    private function deployInPlaceUnderMaintenance(string $sourceDir, string $version, string $logPath, array $excludes, bool $initialInstall): bool
    {
        if (!$this->commandExists('rsync')) {
            $this->log($logPath, 'rsync ist erforderlich für In-Place-Updates.');
            return false;
        }

        if ($initialInstall) {
            $this->log($logPath, 'Initiale Installation erkannt. Webinterface wird erstmals bereitgestellt.');
            if (!is_dir($this->installDir)) {
                mkdir($this->installDir, 0775, true);
            }

            if (!$this->runRsync($sourceDir, $this->installDir, $excludes, $logPath, true)) {
                $this->log($logPath, 'Initiale Installation fehlgeschlagen.');
                return false;
            }

            $this->writeVersionFile($this->installDir, $version);
            if (!$this->runPostDeploy($this->installDir, $logPath)) {
                $this->log($logPath, 'Post-Deploy fehlgeschlagen. Initiale Installation unvollständig.');
                return false;
            }

            return true;
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

    private function isInitialInstall(): bool
    {
        if (!is_dir($this->installDir)) {
            return true;
        }

        $entries = array_filter(scandir($this->installDir) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..');
        return $entries === [];
    }

    private function runPostDeploy(string $baseDir, string $logPath): bool
    {
        $appRoot = $this->resolveAppRoot($baseDir);
        $debugFlag = $this->kernelDebug ? '' : ' --no-debug';
        $envFlag = sprintf(' --env=%s', escapeshellarg($this->kernelEnvironment));

        if ($this->commandExists('composer') && is_file($appRoot . '/composer.json')) {
            $result = $this->runCommand('composer install --no-dev --optimize-autoloader --no-interaction', $appRoot);
            $this->logCommandResult($logPath, 'composer install', $result);
            if ($result['exitCode'] !== 0) {
                return false;
            }

            $autoload = $this->runCommand('composer dump-autoload --no-dev --optimize --no-interaction', $appRoot);
            $this->logCommandResult($logPath, 'composer dump-autoload', $autoload);
            if ($autoload['exitCode'] !== 0) {
                return false;
            }
        } else {
            $this->log($logPath, 'Composer nicht gefunden oder composer.json fehlt. Schritt übersprungen.');
        }

        if (!is_file(PHP_BINARY) && !$this->commandExists(PHP_BINARY)) {
            $this->log($logPath, 'PHP-Binary nicht gefunden. Migrationen/Cache können nicht ausgeführt werden: ' . PHP_BINARY);
            return false;
        }

        $consolePath = $appRoot . '/bin/console';
        if (!is_file($consolePath)) {
            $this->log($logPath, 'bin/console nicht gefunden. Migrationen/Cache können nicht ausgeführt werden.');
            return false;
        }

        $migrate = $this->runCommand(
            $this->buildConsoleCommand('doctrine:migrations:migrate', array_values(array_filter(['--no-interaction', $envFlag, $debugFlag]))),
            $appRoot,
        );
        $this->logCommandResult($logPath, 'doctrine:migrations:migrate', $migrate);
        if ($migrate['exitCode'] !== 0) {
            return false;
        }

        $schema = $this->runCommand(
            $this->buildConsoleCommand('doctrine:schema:validate', array_values(array_filter(['--no-interaction', $envFlag, $debugFlag]))),
            $appRoot,
        );
        $this->logCommandResult($logPath, 'doctrine:schema:validate', $schema);
        if ($schema['exitCode'] !== 0) {
            return false;
        }

        $seedSettings = $this->runCommand(
            $this->buildConsoleCommand('app:settings:ensure-defaults', array_values(array_filter(['--no-interaction', $envFlag, $debugFlag]))),
            $appRoot,
        );
        $this->logCommandResult($logPath, 'app:settings:ensure-defaults', $seedSettings);
        if ($seedSettings['exitCode'] !== 0) {
            return false;
        }

        $this->logMissingEnvSecretsHint($appRoot, $logPath);

        $cache = $this->runCommand(
            $this->buildConsoleCommand('cache:clear', array_values(array_filter([$envFlag, $debugFlag]))),
            $appRoot,
        );
        $this->logCommandResult($logPath, 'cache:clear', $cache);
        if ($cache['exitCode'] !== 0) {
            return false;
        }

        return true;
    }


    private function logMissingEnvSecretsHint(string $appRoot, string $logPath): void
    {
        $check = (new InstallEnvBootstrap())->checkMissing($appRoot);
        if (($check['missing_keys'] ?? []) === []) {
            return;
        }

        $missing = implode(', ', $check['missing_keys']);
        $envPath = (string) ($check['env_path'] ?? rtrim($appRoot, '/\\') . '/.env.local');
        $this->log(
            $logPath,
            sprintf(
                'Hinweis: Fehlende ENV-Secrets (%s). Update überschreibt nicht automatisch. Bitte ausführen: php bin/console app:setup:env-bootstrap (target: %s)',
                $missing,
                $envPath,
            ),
        );
    }

    private function resolveAppRoot(string $baseDir): string
    {
        $coreDir = rtrim($baseDir, '/\\') . '/core';
        return is_dir($coreDir) ? $coreDir : $baseDir;
    }

    private function enableRuntimeMaintenance(string $baseDir, string $logPath): void
    {
        $appRoot = $this->resolveAppRoot($baseDir);
        $maintenanceFile = rtrim($appRoot, '/\\') . '/' . self::RUNTIME_MAINTENANCE_FILE;
        $directory = dirname($maintenanceFile);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->log($logPath, 'Update-Wartungsmodus konnte nicht aktiviert werden: var-Verzeichnis fehlt.');
            return;
        }

        if (@file_put_contents($maintenanceFile, 'Easy-Wi is being updated. Please retry in a moment.' . PHP_EOL) === false) {
            $this->log($logPath, 'Update-Wartungsmodus konnte nicht aktiviert werden.');
            return;
        }

        $this->log($logPath, 'Update-Wartungsmodus aktiviert.');
    }

    private function disableRuntimeMaintenance(string $baseDir, string $logPath): void
    {
        $appRoot = $this->resolveAppRoot($baseDir);
        $maintenanceFile = rtrim($appRoot, '/\\') . '/' . self::RUNTIME_MAINTENANCE_FILE;
        if (!is_file($maintenanceFile)) {
            return;
        }

        if (@unlink($maintenanceFile) === false) {
            $this->log($logPath, 'Update-Wartungsmodus konnte nicht deaktiviert werden: ' . $maintenanceFile);
            return;
        }

        $this->log($logPath, 'Update-Wartungsmodus deaktiviert.');
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
        $excludes = $filtered !== [] ? $filtered : self::DEFAULT_EXCLUDES;

        foreach (self::REQUIRED_RUNTIME_EXCLUDES as $requiredExclude) {
            if (!in_array($requiredExclude, $excludes, true)) {
                $excludes[] = $requiredExclude;
            }
        }

        return $excludes;
    }

    private function shouldUseDelta(?string $installedVersion, UpdateManifest $manifest, string $logPath): bool
    {
        if ($manifest->deltaAssetUrl === null || $manifest->deltaFrom === null) {
            return false;
        }

        if ($this->canUseSymlinkStrategy()) {
            return false;
        }

        $installed = $this->normalizeVersion($installedVersion);
        $deltaFrom = $this->normalizeVersion($manifest->deltaFrom);
        if ($installed === null || $deltaFrom === null) {
            return false;
        }

        if ($installed !== $deltaFrom) {
            $this->log($logPath, sprintf('Delta-Update übersprungen (installed=%s, expected=%s).', $installed, $deltaFrom));
            return false;
        }

        $this->log($logPath, 'Delta-Update wird verwendet.');
        return true;
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

    private function deployDeltaUpdate(string $sourceDir, string $version, string $logPath, array $deletedPaths): bool
    {
        $this->enableRuntimeMaintenance($this->installDir, $logPath);

        try {
            return $this->deployDeltaUpdateUnderMaintenance($sourceDir, $version, $logPath, $deletedPaths);
        } finally {
            $this->disableRuntimeMaintenance($this->installDir, $logPath);
        }
    }

    private function deployDeltaUpdateUnderMaintenance(string $sourceDir, string $version, string $logPath, array $deletedPaths): bool
    {
        if (!$this->commandExists('rsync')) {
            $this->log($logPath, 'rsync ist erforderlich für Delta-Updates.');
            return false;
        }

        $backupDir = rtrim(sys_get_temp_dir(), '/\\') . '/webinterface_backup_' . bin2hex(random_bytes(6));
        mkdir($backupDir, 0770, true);

        $excludes = $this->parseExcludes();
        if (!$this->runRsync($this->installDir, $backupDir, $excludes, $logPath)) {
            $this->log($logPath, 'Backup konnte nicht erstellt werden.');
            return false;
        }

        if (!$this->runRsync($sourceDir, $this->installDir, $excludes, $logPath)) {
            $this->log($logPath, 'Delta-Deploy fehlgeschlagen. Rollback wird durchgeführt.');
            $this->runRsync($backupDir, $this->installDir, [], $logPath, true);
            return false;
        }

        $this->removeDeletedPaths($this->installDir, $deletedPaths, $excludes, $logPath);
        $this->writeVersionFile($this->installDir, $version);
        if (!$this->runPostDeploy($this->installDir, $logPath)) {
            $this->log($logPath, 'Post-Deploy fehlgeschlagen. Rollback wird durchgeführt.');
            $this->runRsync($backupDir, $this->installDir, [], $logPath, true);
            return false;
        }

        return true;
    }

    private function removeDeletedPaths(string $baseDir, array $paths, array $excludes, string $logPath): void
    {
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }

            $trimmed = trim($path);
            if ($trimmed === '') {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', $trimmed), '/');
            if ($relative === '' || $this->matchesExclude($relative, $excludes)) {
                continue;
            }

            $target = rtrim($baseDir, '/\\') . '/' . $relative;
            if (is_link($target) || is_file($target)) {
                unlink($target);
                $this->log($logPath, 'Entfernt: ' . $relative);
                continue;
            }

            if (is_dir($target)) {
                $this->removeDirectory($target);
                $this->log($logPath, 'Ordner entfernt: ' . $relative);
            }
        }
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
        $this->logger?->info('webinterface_update.' . $this->normalizeLogLabel($message), [
            'message' => $message,
            'log_path' => $logPath,
        ]);
    }

    private function commandExists(string $command): bool
    {
        $result = trim((string) shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command))));
        return $result !== '';
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function buildConsoleCommand(string $name, array $arguments = []): array
    {
        return array_merge([PHP_BINARY, 'bin/console', $name], $arguments);
    }

    /**
     * @param string|list<string> $command
     *
     * @return array{exitCode: int, stdout: string, stderr: string, output: string, command: string}
     */
    private function runCommand(string|array $command, string $cwd, int $timeoutSeconds = 600): array
    {
        try {
            $process = is_array($command)
                ? new Process($command, $cwd, null, null, $timeoutSeconds)
                : Process::fromShellCommandline($command, $cwd, null, null, $timeoutSeconds);
            $process->run();

            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());
            $output = trim(implode(PHP_EOL, array_filter([$stdout, $stderr], static fn (string $value): bool => $value !== '')));

            return [
                'exitCode' => $process->getExitCode() ?? 1,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'output' => $output,
                'command' => $this->stringifyCommand($command),
            ];
        } catch (\Throwable $exception) {
            return [
                'exitCode' => 1,
                'stdout' => '',
                'stderr' => $exception->getMessage(),
                'output' => $exception->getMessage(),
                'command' => $this->stringifyCommand($command),
            ];
        }
    }

    /**
     * @param array{exitCode: int, stdout?: string, stderr?: string, output: string, command?: string} $result
     */
    private function logCommandResult(string $logPath, string $label, array $result, bool $warnOnly = false): void
    {
        $context = [
            'label' => $label,
            'exit_code' => $result['exitCode'],
            'command' => $result['command'] ?? $label,
            'stdout' => $result['stdout'] ?? '',
            'stderr' => $result['stderr'] ?? '',
            'log_path' => $logPath,
        ];
        $summary = sprintf('%s exit code: %s', $label, (string) $result['exitCode']);
        $this->log($logPath, $summary);
        if (($result['stdout'] ?? '') !== '') {
            $this->log($logPath, $label . ' stdout: ' . substr((string) $result['stdout'], 0, 4000));
        }
        if (($result['stderr'] ?? '') !== '') {
            $this->log($logPath, $label . ' stderr: ' . substr((string) $result['stderr'], 0, 4000));
        }
        if (($result['stdout'] ?? '') === '' && ($result['stderr'] ?? '') === '' && $result['output'] !== '') {
            $this->log($logPath, $label . ' output: ' . substr($result['output'], 0, 4000));
        }
        if ($result['exitCode'] === 0 || $warnOnly) {
            $this->logger?->info('webinterface_update.command_finished', $context);
            if ($warnOnly && $result['exitCode'] !== 0) {
                $this->log($logPath, $label . ' meldet Warnungen.');
            }
            return;
        }

        $this->logger?->error('webinterface_update.command_failed', $context);
    }

    /**
     * @param array{exitCode: int, stdout?: string, stderr?: string, output: string} $result
     */
    private function formatCommandFailure(string $label, array $result): string
    {
        $detail = trim((string) ($result['output'] ?? ''));
        if ($detail === '') {
            $detail = 'Keine Ausgabe.';
        }

        return sprintf('%s fehlgeschlagen (Exit-Code %d): %s', $label, $result['exitCode'], $detail);
    }

    /**
     * @param string|list<string> $command
     */
    private function stringifyCommand(string|array $command): string
    {
        if (is_string($command)) {
            return $command;
        }

        return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
    }

    private function normalizeLogLabel(string $message): string
    {
        $label = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $message));
        return trim($label, '_') ?: 'message';
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
