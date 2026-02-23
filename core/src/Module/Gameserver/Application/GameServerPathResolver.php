<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Instance;
use Psr\Log\LoggerInterface;

final class GameServerPathResolver
{
    public function __construct(
        private readonly InstanceFilesystemResolver $instanceFilesystemResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveRoot(Instance $server): string
    {
        $path = trim((string) $server->getInstallPath());
        if ($path === '') {
            $path = $this->instanceFilesystemResolver->resolveInstanceDir($server);
            $server->setInstallPath($path);

            $this->logger->warning('gameserver.install_path_bootstrapped_runtime', [
                'server_id' => $server->getId(),
                'install_path' => $path,
            ]);
        }

        $path = $this->normalizeLegacyShortPath($server, $path);

        if (!str_starts_with($path, '/')) {
            throw new \RuntimeException('INVALID_SERVER_ROOT');
        }

        $this->logger->info('gameserver.path_resolved', [
            'server_id' => $server->getId(),
            'resolved_path' => $path,
        ]);

        return $path;
    }

    private function normalizeLegacyShortPath(Instance $server, string $path): string
    {
        if (preg_match('#^/gs[0-9a-z]+$#i', $path) !== 1) {
            return $path;
        }

        $baseDir = trim((string) ($server->getInstanceBaseDir() ?? ''));
        if ($baseDir === '' || !str_starts_with($baseDir, '/')) {
            return $path;
        }

        $resolved = rtrim($baseDir, '/') . '/' . ltrim($path, '/');
        $this->logger->warning('gameserver.install_path_legacy_short_resolved', [
            'server_id' => $server->getId(),
            'legacy_path' => $path,
            'resolved_path' => $resolved,
        ]);

        return $resolved;
    }

    public function assertExistsAndAccessible(string $path): void
    {
        if (!str_starts_with($path, '/')) {
            throw new \RuntimeException('INVALID_SERVER_ROOT');
        }
        if (!is_dir($path)) {
            throw new \RuntimeException('SERVER_ROOT_NOT_FOUND');
        }
        if (!is_readable($path) || !is_executable($path)) {
            throw new \RuntimeException('SERVER_ROOT_NOT_ACCESSIBLE');
        }
    }
}
