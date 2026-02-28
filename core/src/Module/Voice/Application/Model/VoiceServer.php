<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Model;

final readonly class VoiceServer
{
    public function __construct(
        private string $id,
        private string $provider,
        private string $host,
        private int $queryPort,
        private int $voicePort,
        private int $maxParallelQueries = 2,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function queryPort(): int
    {
        return $this->queryPort;
    }

    public function voicePort(): int
    {
        return $this->voicePort;
    }

    public function maxParallelQueries(): int
    {
        return max(1, $this->maxParallelQueries);
    }
}
