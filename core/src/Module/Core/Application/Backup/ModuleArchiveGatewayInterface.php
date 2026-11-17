<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Core\Domain\Entity\Agent;

/** Authenticated, completed agent operations, streaming archives to/from local staging. */
interface ModuleArchiveGatewayInterface
{
    /**
     * @param array<string, scalar|array|null> $descriptor
     * Descriptor: module, resource_id, path (file modules), optional service,
     * database (db module: host, port, name, username, password, tls_mode).
     * The agent returns a gzip tar containing the actual resource data.
     */
    public function snapshot(Agent $agent, string $customerId, array $descriptor, string $destinationArchive): void;

    /** @param array<string, scalar|array|null> $descriptor Same resource binding as snapshot. */
    public function restore(Agent $agent, string $customerId, array $descriptor, string $archivePath, bool $dryRun): RestoreReport;
}
