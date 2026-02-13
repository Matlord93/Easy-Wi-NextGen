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

        if (!str_starts_with($path, '/')) {
            throw new \RuntimeException('INVALID_SERVER_ROOT');
        }

        $this->logger->info('gameserver.path_resolved', [
            'server_id' => $server->getId(),
            'resolved_path' => $path,
        ]);

        return $path;
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
