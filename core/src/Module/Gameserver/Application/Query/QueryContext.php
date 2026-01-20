<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

final class QueryContext
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly ?string $host,
        private readonly ?int $gamePort,
        private readonly ?int $queryPort,
        private readonly ?int $rconPort,
        private readonly array $config,
    ) {
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function getGamePort(): ?int
    {
        return $this->gamePort;
    }

    public function getQueryPort(): ?int
    {
        return $this->queryPort;
    }

    public function getRconPort(): ?int
    {
        return $this->rconPort;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
