<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Driver;

use App\Module\Voice\Application\Model\PermissionSet;
use App\Module\Voice\Application\Model\VoiceUser;

final class Teamspeak3Driver extends AbstractTeamspeakDriver
{
    #[\Override]
    public function provider(): string
    {
        return 'ts3';
    }

    #[\Override]
    protected function serverCreateCommands(): array
    {
        return [['servercreate', ['virtualserver_name' => 'EasyWI-TS3']]];
    }

    #[\Override]
    protected function listUserCommands(): array
    {
        return [['clientlist', ['uid' => 1, 'away' => 1]]];
    }

    #[\Override]
    protected function userManagementCommands(VoiceUser $user): array
    {
        return [['clientupdate', ['clid' => $user->id(), 'client_nickname' => $user->nickname()]]];
    }

    #[\Override]
    protected function permissionCommands(VoiceUser $user, PermissionSet $permissions): array
    {
        return [['clientaddperm', ['cldbid' => $user->id(), 'permsid' => implode(',', $permissions->permissions())]]];
    }

    #[\Override]
    protected function tokenCommands(PermissionSet $permissions): array
    {
        return [['tokenadd', ['tokentype' => 0, 'tokenid1' => implode(',', $permissions->permissions())]]];
    }
}
