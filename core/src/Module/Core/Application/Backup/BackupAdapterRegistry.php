<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\BackupModuleAdapterInterface;

final class BackupAdapterRegistry
{
    /** @var array<string, BackupModuleAdapterInterface> */
    private array $adapters = [];

    /** @param iterable<BackupModuleAdapterInterface> $adapters */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->module()] = $adapter;
        }
    }

    public function forModule(string $module): BackupModuleAdapterInterface
    {
        if (!array_key_exists($module, $this->adapters)) {
            throw new \InvalidArgumentException('No backup adapter configured for module '.$module);
        }

        return $this->adapters[$module];
    }
}
