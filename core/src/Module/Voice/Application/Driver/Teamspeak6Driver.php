<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Driver;

use App\Module\Voice\Application\Model\PermissionSet;
use App\Module\Voice\Application\Model\VoiceUser;

final class Teamspeak6Driver extends AbstractTeamspeakDriver
{
    #[\Override]
    public function provider(): string
    {
        return 'ts6';
    }

    /** @return list<array{0: string, 1: array{name: string}}> */
    #[\Override]
    protected function serverCreateCommands(): array
    {
        return [['servercreate', ['name' => 'EasyWI-TS6']]];
    }

    /** @return list<array{0: string, 1: array{groups: int, country: int}}> */
    #[\Override]
    protected function listUserCommands(): array
    {
        return [['clientlist', ['groups' => 1, 'country' => 1]]];
    }

    /** @return list<array{0: string, 1: array{cldbid: string, nickname: string}}> */
    #[\Override]
    protected function userManagementCommands(VoiceUser $user): array
    {
        return [['clientedit', ['cldbid' => $user->id(), 'nickname' => $user->nickname()]]];
    }

    /** @return list<array{0: string, 1: array{target: string, perms: string}}> */
    #[\Override]
    protected function permissionCommands(VoiceUser $user, PermissionSet $permissions): array
    {
        return [['permissionassign', ['target' => $user->id(), 'perms' => implode(',', $permissions->permissions())]]];
    }

    /** @return list<array{0: string, 1: array{scope: string, perms: string}}> */
    #[\Override]
    protected function tokenCommands(PermissionSet $permissions): array
    {
        return [['privilegekeyadd', ['scope' => 'server', 'perms' => implode(',', $permissions->permissions())]]];
    }
}
