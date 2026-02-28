<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Driver;

use App\Module\Voice\Application\Model\PermissionSet;
use App\Module\Voice\Application\Model\VoiceUser;

final class Teamspeak6Driver extends AbstractTeamspeakDriver
{
    public function provider(): string
    {
        return 'ts6';
    }

    protected function serverCreateCommands(): array
    {
        return [['servercreate', ['name' => 'EasyWI-TS6']]];
    }

    protected function listUserCommands(): array
    {
        return [['clientlist', ['groups' => 1, 'country' => 1]]];
    }

    protected function userManagementCommands(VoiceUser $user): array
    {
        return [['clientedit', ['cldbid' => $user->id(), 'nickname' => $user->nickname()]]];
    }

    protected function permissionCommands(VoiceUser $user, PermissionSet $permissions): array
    {
        return [['permissionassign', ['target' => $user->id(), 'perms' => implode(',', $permissions->permissions())]]];
    }

    protected function tokenCommands(PermissionSet $permissions): array
    {
        return [['privilegekeyadd', ['scope' => 'server', 'perms' => implode(',', $permissions->permissions())]]];
    }
}
