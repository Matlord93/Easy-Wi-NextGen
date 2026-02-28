<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Driver;

use App\Module\Teamspeak\Application\Query\QueryCommandBuilder;
use App\Module\Teamspeak\Application\Query\QueryContext;
use App\Module\Teamspeak\Application\Query\QueryRequest;
use App\Module\Teamspeak\Application\Query\TeamSpeakQueryClientInterface;
use App\Module\Voice\Application\Model\PermissionSet;
use App\Module\Voice\Application\Model\VoiceServer;
use App\Module\Voice\Application\Model\VoiceUser;
use App\Module\Voice\Application\Query\VoiceQueryEngine;

abstract class AbstractTeamspeakDriver implements VoiceDriver
{
    public function __construct(
        private readonly TeamSpeakQueryClientInterface $queryClient,
        private readonly QueryCommandBuilder $commandBuilder,
        private readonly QueryContext $context,
    ) {
    }

    public function supports(VoiceServer $server): bool
    {
        return $server->provider() === $this->provider();
    }

    public function createServer(VoiceServer $server, VoiceQueryEngine $engine): array
    {
        return $engine->execute($server, function () {
            return $this->executeCommands($this->serverCreateCommands());
        }, retryable: false);
    }

    public function listUsers(VoiceServer $server, VoiceQueryEngine $engine): array
    {
        return $engine->execute($server, fn () => $this->executeCommands($this->listUserCommands()));
    }

    public function manageUser(VoiceServer $server, VoiceUser $user, VoiceQueryEngine $engine): array
    {
        return $engine->execute($server, fn () => $this->executeCommands($this->userManagementCommands($user)), retryable: false);
    }

    public function applyPermissions(VoiceServer $server, VoiceUser $user, PermissionSet $permissions, VoiceQueryEngine $engine): array
    {
        return $engine->execute($server, fn () => $this->executeCommands($this->permissionCommands($user, $permissions)));
    }

    public function createToken(VoiceServer $server, PermissionSet $permissions, VoiceQueryEngine $engine): array
    {
        return $engine->execute($server, fn () => $this->executeCommands($this->tokenCommands($permissions)), retryable: false);
    }

    /** @return list<array{0:string,1:array<string, scalar|null>}> */
    abstract protected function serverCreateCommands(): array;

    /** @return list<array{0:string,1:array<string, scalar|null>}> */
    abstract protected function listUserCommands(): array;

    /** @return list<array{0:string,1:array<string, scalar|null>}> */
    abstract protected function userManagementCommands(VoiceUser $user): array;

    /** @return list<array{0:string,1:array<string, scalar|null>}> */
    abstract protected function permissionCommands(VoiceUser $user, PermissionSet $permissions): array;

    /** @return list<array{0:string,1:array<string, scalar|null>}> */
    abstract protected function tokenCommands(PermissionSet $permissions): array;

    /** @param list<array{0:string,1:array<string, scalar|null>}> $spec */
    private function executeCommands(array $spec): array
    {
        $commands = [];
        foreach ($spec as [$command, $args]) {
            $commands[] = $this->commandBuilder->build($command, $args);
        }

        return $this->queryClient->execute(new QueryRequest($commands), $this->context)->payload();
    }
}
