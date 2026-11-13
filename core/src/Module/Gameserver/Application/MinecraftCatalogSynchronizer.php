<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;

final class MinecraftCatalogSynchronizer
{
    private bool $prepared = false;

    public function __construct(
        private readonly MinecraftUpstreamCatalog $upstream,
        private readonly MinecraftVersionCatalogRepository $repository,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/var/minecraft-catalog')] private readonly string $directory,
        private readonly ?\App\Module\Core\Application\GameTemplateSeeder $templateSeeder = null,
        private readonly ?\App\Module\Core\Application\MinecraftVersionImportService $bootstrapImporter = null,
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
        #[Autowire('%env(default:app.php_cli_binary_default:EASYWI_PHP_BIN)%')] private readonly string $phpCliBinary = '',
    ) {
    }

    public function status(): array
    {
        $file = $this->directory.'/status.json';
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        $running = false;
        if (is_file($this->directory.'/sync.lock') && ($lock = @fopen($this->directory.'/sync.lock', 'r+')) !== false) {
            $running = !flock($lock, LOCK_EX | LOCK_NB);
            if (!$running) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }

        return array_replace(is_array($status) ? $status : [], ['queued' => is_file($this->directory.'/refresh'), 'running' => $running]);
    }

    /** First use works from release metadata; upstream checks happen in the background. */
    public function ensureAvailable(): void
    {
        if ($this->prepared) {
            return;
        }
        $this->prepared = true;
        try {
            $this->bootstrap();
            $this->startIfDue();
        } catch (\Throwable $error) {
            $this->logger?->warning('minecraft.catalog_initialization_failed', ['exception' => $error]);
        }
    }

    /** Safe status for panel users; upstream exception details stay in server logs. */
    public function publicStatus(): array
    {
        $status = $this->status();
        $channels = [];
        foreach (MinecraftVersionCatalog::CHANNELS as $channel) {
            $entry = $status['channels'][$channel] ?? [];
            $channels[$channel] = ['updated_at' => (int) ($entry['success_at'] ?? 0), 'unavailable' => !empty($entry['error'])];
        }

        return ['updating' => $status['queued'] || $status['running'], 'channels' => $channels];
    }

    private function bootstrap(): void
    {
        if (null === $this->bootstrapImporter) {
            return;
        }
        $this->ensureDirectory();
        $lock = fopen($this->directory.'/sync.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException('Cannot open catalog lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }
        try {
            $this->templateSeeder?->seedTemplatesOnly(null, ['minecraft_vanilla_all', 'minecraft_paper_all', 'minecraft_bedrock', 'minecraft_spigot_all', 'minecraft_bungeecord_all'], true);
            foreach (\App\Module\Core\Application\MinecraftVersionImportService::CHANNELS as $channel) {
                if (0 === $this->repository->count(['channel' => $channel])) {
                    $result = $this->bootstrapImporter->import([$channel]);
                    if ([] !== $result['errors']) {
                        $this->logger?->warning('minecraft.catalog_bootstrap_unavailable', ['channel' => $channel, 'errors' => $result['errors']]);
                    }
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function requestRefresh(): void
    {
        $this->ensureDirectory();
        if (false === file_put_contents($this->directory.'/refresh', '1', LOCK_EX)) {
            throw new \RuntimeException('Cannot queue catalog refresh.');
        }
    }

    /** Dispatch the potentially slow first import without blocking game schedules. */
    public function startIfDue(): void
    {
        $this->ensureDirectory();
        $status = $this->status();
        if ($status['running'] || (!$status['queued'] && time() - (int) ($status['attempted_at'] ?? 0) < $this->retryInterval($status))) {
            return;
        }
        $lock = fopen($this->directory.'/dispatch.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException('Cannot open catalog lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }
        try {
            $dispatched = is_file($this->directory.'/dispatched') ? (int) file_get_contents($this->directory.'/dispatched') : 0;
            if (time() - $dispatched < 30) {
                return;
            }
            $console = dirname($this->directory, 2).'/bin/console';
            // PHP_BINARY is php-fpm for web requests; workers require the CLI SAPI.
            $binary = trim($this->phpCliBinary);
            if ('' === $binary) {
                $matchingVersion = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
                $binary = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : (is_executable($matchingVersion) ? $matchingVersion : (new PhpExecutableFinder())->find(false));
            }
            if (!$binary || !is_executable($binary) || is_dir($binary) || str_contains(basename($binary), 'fpm')) {
                throw new \RuntimeException('No PHP CLI binary available for catalog updates.');
            }
            $command = 'nohup '.escapeshellarg($binary);
            if ($ini = php_ini_loaded_file()) {
                $command .= ' -c '.escapeshellarg($ini);
            }
            $command .= ' '.escapeshellarg($console).' catalog:sync:minecraft --no-interaction >> '.escapeshellarg($this->directory.'/sync.log').' 2>&1 < /dev/null &';
            $process = \Symfony\Component\Process\Process::fromShellCommandline($command);
            $this->requestRefresh();
            file_put_contents($this->directory.'/dispatched', (string) time(), LOCK_EX);
            $process->mustRun();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function synchronize(bool $force = false): array
    {
        $this->ensureDirectory();
        $this->bootstrap();
        $lock = fopen($this->directory.'/sync.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException('Cannot open Minecraft catalog lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return $this->status();
        }
        try {
            $status = $this->status();
            $this->templateSeeder?->seedTemplatesOnly(null, ['minecraft_vanilla_all', 'minecraft_paper_all', 'minecraft_bedrock', 'minecraft_spigot_all', 'minecraft_bungeecord_all'], true);
            if (!$force && !$status['queued'] && time() - (int) ($status['attempted_at'] ?? 0) < $this->retryInterval($status)) {
                return $status;
            }
            @unlink($this->directory.'/refresh');
            $status['attempted_at'] = time();
            $status['queued'] = false;
            $this->writeStatus($status);
            // Discover channels without bundled metadata before the large Mojang history.
            foreach (array_unique(['spigot', 'bungeecord', ...MinecraftVersionCatalog::CHANNELS]) as $channel) {
                try {
                    // Fully fetch a provider before changing its previous successful catalog.
                    $items = iterator_to_array($this->upstream->entries($channel), false);
                    if ([] === $items) {
                        throw new \RuntimeException('Provider returned no installable versions.');
                    }
                    foreach ($items as &$item) {
                        if (!is_string($item['version'] ?? null) || !str_starts_with((string) ($item['url'] ?? ''), 'https://')) {
                            throw new \RuntimeException('Invalid upstream artifact metadata.');
                        }
                        $item['date'] = isset($item['released']) ? new \DateTimeImmutable($item['released']) : null;
                        if (isset($item['java'])) {
                            (new MinecraftJavaVersionResolver())->resolve($item['version'], (string) $item['java']);
                        }
                    }
                    unset($item);
                    $count = 0;
                    foreach ($items as $item) {
                        $criteria = ['channel' => $channel, 'mcVersion' => $item['version'], 'build' => $item['build'] ?? null];
                        $entry = $this->repository->findOneBy($criteria);
                        if ($entry instanceof MinecraftVersionCatalog && 'manual' === $entry->getSource()) {
                            continue;
                        }
                        if (!$entry instanceof MinecraftVersionCatalog) {
                            $entry = new MinecraftVersionCatalog($channel, $item['version'], $item['build'] ?? null, $item['url']);
                            $this->em->persist($entry);
                        }
                        $entry->setDownloadUrl($item['url']);
                        $entry->setSha256($item['sha256'] ?? null);
                        $entry->setJavaVersion($item['java'] ?? null);
                        $entry->setMetadata($item['metadata'] ?? []);
                        $entry->setSource('sync');
                        $entry->setReleasedAt($item['date']);
                        // Do not reactivate versions deliberately disabled by an administrator.
                        ++$count;
                    }
                    $this->em->flush();
                    $status['channels'][$channel] = ['success_at' => time(), 'count' => $count, 'error' => null];
                } catch (\Throwable $error) {
                    $previous = $status['channels'][$channel] ?? [];
                    $status['channels'][$channel] = $previous + ['count' => 0];
                    $status['channels'][$channel]['error'] = $error->getMessage();
                    $this->logger?->warning('minecraft.catalog_provider_failed', ['channel' => $channel, 'exception' => $error]);
                }
                $this->writeStatus($status);
            }

            return $status;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function retryInterval(array $status): int
    {
        foreach ($status['channels'] ?? [] as $channel) {
            if (!empty($channel['success_at'])) {
                return 3600;
            }
        }

        return 60;
    }

    private function writeStatus(array $status): void
    {
        $tmp = tempnam($this->directory, 'status-');
        if (false === $tmp || false === file_put_contents($tmp, json_encode($status, JSON_THROW_ON_ERROR)) || !rename($tmp, $this->directory.'/status.json')) {
            throw new \RuntimeException('Cannot save catalog synchronization status.');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create catalog state directory.');
        }
    }
}
