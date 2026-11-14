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

    /** @return list<array{0: string, 1: array{virtualserver_name: string}}> */
    #[\Override]
    protected function serverCreateCommands(): array
    {
        return [['servercreate', ['virtualserver_name' => 'EasyWI-TS3']]];
    }

    /** @return list<array{0: string, 1: array{uid: int, away: int}}> */
    #[\Override]
    protected function listUserCommands(): array
    {
        return [['clientlist', ['uid' => 1, 'away' => 1]]];
    }

    /** @return list<array{0: string, 1: array{clid: string, client_nickname: string}}> */
    #[\Override]
    protected function userManagementCommands(VoiceUser $user): array
    {
        return [['clientupdate', ['clid' => $user->id(), 'client_nickname' => $user->nickname()]]];
    }

    /** @return list<array{0: string, 1: array{cldbid: string, permsid: string}}> */
    #[\Override]
    protected function permissionCommands(VoiceUser $user, PermissionSet $permissions): array
    {
        return [['clientaddperm', ['cldbid' => $user->id(), 'permsid' => implode(',', $permissions->permissions())]]];
    }

    /** @return list<array{0: string, 1: array{tokentype: int, tokenid1: string}}> */
    #[\Override]
    protected function tokenCommands(PermissionSet $permissions): array
    {
        return [['tokenadd', ['tokentype' => 0, 'tokenid1' => implode(',', $permissions->permissions())]]];
    }
}
