<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ModuleBackupOperationsRegistry
{
    /** @var array<string, ModuleBackupOperationsInterface> */
    private array $operations = [];

    /** @param iterable<ModuleBackupOperationsInterface> $operations */
    public function __construct(#[AutowireIterator('app.module_backup_operations')] iterable $operations = [])
    {
        foreach ($operations as $operation) {
            $module = $operation->module();
            if (isset($this->operations[$module])) {
                throw new \InvalidArgumentException('Duplicate backup operation provider for '.$module);
            }
            $this->operations[$module] = $operation;
        }
    }

    public function find(string $module): ?ModuleBackupOperationsInterface
    {
        return $this->operations[$module] ?? null;
    }
}
