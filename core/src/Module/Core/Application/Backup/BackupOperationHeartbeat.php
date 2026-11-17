<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

/** Carries the current worker's lease renewal through blocking HTTP transfers. */
final class BackupOperationHeartbeat
{
    private ?\Closure $callback = null;
    private float $nextPulse = 0.0;

    /** @param callable():void $callback */
    public function enter(callable $callback): ?\Closure
    {
        $previous = $this->callback;
        $this->callback = \Closure::fromCallable($callback);
        $this->nextPulse = 0.0;

        return $previous;
    }

    public function leave(?\Closure $previous): void
    {
        $this->callback = $previous;
        $this->nextPulse = 0.0;
    }

    public function pulse(bool $force = false): void
    {
        if ($this->callback !== null && ($force || microtime(true) >= $this->nextPulse)) {
            ($this->callback)();
            $this->nextPulse = microtime(true) + 5.0;
        }
    }
}
