<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class SshConnectionConfig
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly ?string $password = null,
        private readonly ?string $privateKey = null,
        private readonly int $timeoutSeconds = 12,
    ) {
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    public function privateKey(): ?string
    {
        return $this->privateKey;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }
}
