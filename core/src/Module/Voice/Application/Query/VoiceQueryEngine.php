<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Query;

use App\Module\Voice\Application\Model\VoiceServer;

final class VoiceQueryEngine
{
    /** @var array<string, int> */
    private array $inFlight = [];
    /** @var array<string, int> */
    private array $reentrancyDepth = [];
    /** @var array<string, int> */
    private array $consecutiveFailures = [];
    /** @var array<string, int> */
    private array $circuitOpenUntilMs = [];
    /** @var array<string, bool> */
    private array $halfOpenInProgress = [];
    /** @var array<string, int> */
    private array $lastExecutionMs = [];

    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly int $timeoutMs = 1500,
        private readonly int $baseBackoffMs = 50,
        private readonly int $minIntervalMs = 25,
        private readonly int $circuitBreakerFailures = 4,
        private readonly int $circuitBreakerTtlMs = 2500,
        private readonly ?\Closure $sleep = null,
        private readonly ?\Closure $clockMs = null,
    ) {
    }

    /**
     * @template T
     * @param \Closure(int):T $operation
     * @return T
     */
    public function execute(VoiceServer $server, \Closure $operation, bool $retryable = true)
    {
        $serverId = $server->id();
        $now = $this->nowMs();

        $isHalfOpen = false;
        if (($this->circuitOpenUntilMs[$serverId] ?? 0) > $now) {
            throw new VoiceQueryException('Circuit breaker is open for server ' . $serverId . '.');
        }

        if (($this->consecutiveFailures[$serverId] ?? 0) >= $this->circuitBreakerFailures) {
            if (($this->halfOpenInProgress[$serverId] ?? false) === true) {
                throw new VoiceQueryException('Circuit breaker half-open probe is in progress for server ' . $serverId . '.');
            }

            $isHalfOpen = true;
            $this->halfOpenInProgress[$serverId] = true;
        }

        $isReentrant = ($this->reentrancyDepth[$serverId] ?? 0) > 0;
        if (!$isReentrant && ($this->inFlight[$serverId] ?? 0) >= $server->maxParallelQueries()) {
            throw new VoiceQueryException('Per-server query concurrency limit reached.');
        }

        $this->inFlight[$serverId] = ($this->inFlight[$serverId] ?? 0) + 1;
        $this->reentrancyDepth[$serverId] = ($this->reentrancyDepth[$serverId] ?? 0) + 1;

        try {
            $this->applyRateLimit($serverId);

            $maxAttempts = $retryable ? $this->maxRetries : 0;
            for ($attempt = 0; $attempt <= $maxAttempts; ++$attempt) {
                $started = $this->nowMs();

                try {
                    $result = $operation($attempt);
                    $duration = $this->nowMs() - $started;
                    if ($duration > $this->timeoutMs) {
                        throw new VoiceQueryException('Query timed out after ' . $duration . 'ms.');
                    }

                    $this->consecutiveFailures[$serverId] = 0;
                    $this->circuitOpenUntilMs[$serverId] = 0;
                    $this->lastExecutionMs[$serverId] = $this->nowMs();

                    return $result;
                } catch (\Throwable $e) {
                    if (!$this->isTransientError($e)) {
                        throw new VoiceQueryException('Query failed with non-transient error: ' . $e->getMessage(), 0, $e);
                    }

                    $this->consecutiveFailures[$serverId] = ($this->consecutiveFailures[$serverId] ?? 0) + 1;
                    if ($this->consecutiveFailures[$serverId] >= $this->circuitBreakerFailures) {
                        $this->circuitOpenUntilMs[$serverId] = $this->nowMs() + $this->circuitBreakerTtlMs;
                    }

                    if ($isHalfOpen) {
                        throw new VoiceQueryException('Query failed during half-open probe: ' . $e->getMessage(), 0, $e);
                    }

                    if ($attempt >= $maxAttempts) {
                        throw new VoiceQueryException('Query failed after retries: ' . $e->getMessage(), 0, $e);
                    }

                    $backoff = (int) ($this->baseBackoffMs * (2 ** $attempt));
                    $this->sleepMs($backoff);
                }
            }
        } finally {
            if ($isHalfOpen) {
                $this->halfOpenInProgress[$serverId] = false;
            }
            $this->reentrancyDepth[$serverId] = max(0, ($this->reentrancyDepth[$serverId] ?? 1) - 1);
            $this->inFlight[$serverId] = max(0, ($this->inFlight[$serverId] ?? 1) - 1);
        }

        throw new VoiceQueryException('Query engine exhausted unexpectedly.');
    }

    private function applyRateLimit(string $serverId): void
    {
        $last = $this->lastExecutionMs[$serverId] ?? null;
        if ($last === null) {
            return;
        }

        $elapsed = $this->nowMs() - $last;
        if ($elapsed < $this->minIntervalMs) {
            $this->sleepMs($this->minIntervalMs - $elapsed);
        }
    }

    private function nowMs(): int
    {
        if ($this->clockMs instanceof \Closure) {
            return (int) ($this->clockMs)();
        }

        return (int) floor(microtime(true) * 1000);
    }

    private function sleepMs(int $durationMs): void
    {
        if ($this->sleep instanceof \Closure) {
            ($this->sleep)($durationMs);
            return;
        }

        usleep($durationMs * 1000);
    }

    private function isTransientError(\Throwable $error): bool
    {
        if ($error instanceof VoiceQueryException) {
            return true;
        }

        if (!$error instanceof \RuntimeException) {
            return false;
        }

        $message = strtolower($error->getMessage());
        foreach (['timeout', 'timed out', 'temporar', 'rate-limit', 'rate limit', 'locked', 'unavailable', 'connection reset'] as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }
}
