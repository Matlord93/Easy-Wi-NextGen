<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class QueryContext
{
    public function __construct(
        private readonly string $tsVersion,
        private readonly int $sid,
        private readonly string $queryHost,
        private readonly int $queryPort,
        private readonly ?string $queryUser,
        private readonly ?string $queryPassword,
        private readonly SshConnectionConfig $sshConfig,
        private readonly string $runnerPath = '/usr/local/bin/ts-query-runner',
        private readonly ?int $serverId = null,
        private readonly ?int $customerId = null,
        private readonly string $transport = 'ssh',
    ) {
    }

    public function tsVersion(): string
    {
        return $this->tsVersion;
    }

    public function sid(): int
    {
        return $this->sid;
    }

    public function queryHost(): string
    {
        return $this->queryHost;
    }

    public function queryPort(): int
    {
        return $this->queryPort;
    }

    public function queryUser(): ?string
    {
        return $this->queryUser;
    }

    public function queryPassword(): ?string
    {
        return $this->queryPassword;
    }

    public function sshConfig(): SshConnectionConfig
    {
        return $this->sshConfig;
    }

    public function runnerPath(): string
    {
        return $this->runnerPath;
    }

    public function serverId(): ?int
    {
        return $this->serverId;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function transport(): string
    {
        return $this->transport;
    }
}
