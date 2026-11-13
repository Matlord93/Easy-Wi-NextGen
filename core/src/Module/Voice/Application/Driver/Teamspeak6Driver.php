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

    #[\Override]
    protected function serverCreateCommands(): array
    {
        return [['servercreate', ['name' => 'EasyWI-TS6']]];
    }

    #[\Override]
    protected function listUserCommands(): array
    {
        return [['clientlist', ['groups' => 1, 'country' => 1]]];
    }

    #[\Override]
    protected function userManagementCommands(VoiceUser $user): array
    {
        return [['clientedit', ['cldbid' => $user->id(), 'nickname' => $user->nickname()]]];
    }

    #[\Override]
    protected function permissionCommands(VoiceUser $user, PermissionSet $permissions): array
    {
        return [['permissionassign', ['target' => $user->id(), 'perms' => implode(',', $permissions->permissions())]]];
    }

    #[\Override]
    protected function tokenCommands(PermissionSet $permissions): array
    {
        return [['privilegekeyadd', ['scope' => 'server', 'perms' => implode(',', $permissions->permissions())]]];
    }
}
