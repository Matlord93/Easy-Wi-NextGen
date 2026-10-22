<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MinecraftCatalogSynchronizer
{
    public function __construct(
        private readonly MinecraftUpstreamCatalog $upstream,
        private readonly MinecraftVersionCatalogRepository $repository,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/var/minecraft-catalog')] private readonly string $directory,
        private readonly ?\App\Module\Core\Application\GameTemplateSeeder $templateSeeder = null,
    ) {
    }

    public function status(): array
    {
        $file = $this->directory.'/status.json';
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return array_replace(is_array($status) ? $status : [], ['queued' => is_file($this->directory.'/refresh')]);
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
        if (!$status['queued'] && time() - (int) ($status['attempted_at'] ?? 0) < 3600) {
            return;
        }
        $lock = fopen($this->directory.'/sync.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException('Cannot open catalog lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        $console = dirname($this->directory, 2).'/bin/console';
        $command = 'nohup '.escapeshellarg(PHP_BINARY);
        if ($ini = php_ini_loaded_file()) {
            $command .= ' -c '.escapeshellarg($ini);
        }
        $command .= ' '.escapeshellarg($console).' catalog:sync:minecraft --no-interaction >> '.escapeshellarg($this->directory.'/sync.log').' 2>&1 < /dev/null &';
        $process = \Symfony\Component\Process\Process::fromShellCommandline($command);
        $process->mustRun();
    }

    public function synchronize(bool $force = false): array
    {
        $this->ensureDirectory();
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
            if (!$force && !$status['queued'] && time() - (int) ($status['attempted_at'] ?? 0) < 3600) {
                return $status;
            }
            @unlink($this->directory.'/refresh');
            $status['attempted_at'] = time();
            $status['queued'] = false;
            foreach (MinecraftVersionCatalog::CHANNELS as $channel) {
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
                }
            }
            $tmp = tempnam($this->directory, 'status-');
            if (false === $tmp || false === file_put_contents($tmp, json_encode($status, JSON_THROW_ON_ERROR)) || !rename($tmp, $this->directory.'/status.json')) {
                throw new \RuntimeException('Cannot save catalog synchronization status.');
            }

            return $status;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create catalog state directory.');
        }
    }
}
