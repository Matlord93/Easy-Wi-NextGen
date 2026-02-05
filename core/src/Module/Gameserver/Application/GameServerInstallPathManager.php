<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Instance;
use Psr\Log\LoggerInterface;

final class GameServerInstallPathManager
{
    public function __construct(
        private readonly InstanceFilesystemResolver $legacyFilesystemResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ensureInstallPath(Instance $instance): string
    {
        $path = trim((string) $instance->getInstallPath());
        if ($path !== '') {
            return $path;
        }

        $path = $this->legacyFilesystemResolver->resolveInstanceDir($instance);
        $instance->setInstallPath($path);

        $this->logger->warning('gameserver.install_path_bootstrapped', [
            'server_id' => $instance->getId(),
            'install_path' => $path,
            'legacy' => true,
        ]);

        return $path;
    }
}
