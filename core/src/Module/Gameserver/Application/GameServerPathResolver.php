<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use Psr\Log\LoggerInterface;

final class GameServerPathResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveRoot(Instance $server): string
    {
        $path = trim((string) $server->getInstallPath());
        if ($path === '' || !str_starts_with($path, '/')) {
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
