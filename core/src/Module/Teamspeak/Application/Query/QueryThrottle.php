<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class QueryThrottle
{
    /**
     * @var array<string, array{tokens: float, last: float}>
     */
    private array $state = [];

    public function __construct(
        private readonly int $capacity,
        private readonly float $refillPerSecond,
    ) {
    }

    public function allow(string $scope, int $cost = 1, ?float $now = null): bool
    {
        $timestamp = $now ?? microtime(true);
        $bucket = $this->state[$scope] ?? ['tokens' => (float) $this->capacity, 'last' => $timestamp];

        $elapsed = max(0.0, $timestamp - $bucket['last']);
        $bucket['tokens'] = min((float) $this->capacity, $bucket['tokens'] + ($elapsed * $this->refillPerSecond));
        $bucket['last'] = $timestamp;

        if ($bucket['tokens'] < $cost) {
            $this->state[$scope] = $bucket;
            return false;
        }

        $bucket['tokens'] -= $cost;
        $this->state[$scope] = $bucket;

        return true;
    }
}
