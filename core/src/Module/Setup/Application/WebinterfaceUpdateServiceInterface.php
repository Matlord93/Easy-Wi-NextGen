<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Module\Core\Update\UpdateResult;

interface WebinterfaceUpdateServiceInterface
{
    public function applyUpdate(): UpdateResult;

    public function applyMigrations(): UpdateResult;

    /**
     * Polls the status of the web.stack_reload agent job dispatched by applyUpdate().
     * Returns a pending result while the job is still queued/running, a success result
     * once the agent confirms completion, and a non-fatal success with a warning when the
     * job timed out or was not configured.
     */
    public function awaitAgentReload(string $agentJobId): UpdateResult;
}
