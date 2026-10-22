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
     * once the agent confirms completion. Missing, failed and timed-out reload jobs
     * fail activation; an unconfigured reload is skipped for browser OPcache resets.
     */
    public function awaitAgentReload(string $agentJobId): UpdateResult;
}
