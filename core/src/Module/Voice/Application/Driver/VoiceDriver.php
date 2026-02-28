<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Driver;

use App\Module\Voice\Application\Model\PermissionSet;
use App\Module\Voice\Application\Model\VoiceServer;
use App\Module\Voice\Application\Model\VoiceUser;
use App\Module\Voice\Application\Query\VoiceQueryEngine;

interface VoiceDriver
{
    public function provider(): string;

    public function supports(VoiceServer $server): bool;

    /** @return array<string, mixed> */
    public function createServer(VoiceServer $server, VoiceQueryEngine $engine): array;

    /** @return list<array<string, mixed>> */
    public function listUsers(VoiceServer $server, VoiceQueryEngine $engine): array;

    /** @return array<string, mixed> */
    public function manageUser(VoiceServer $server, VoiceUser $user, VoiceQueryEngine $engine): array;

    /** @return array<string, mixed> */
    public function applyPermissions(VoiceServer $server, VoiceUser $user, PermissionSet $permissions, VoiceQueryEngine $engine): array;

    /** @return array<string, mixed> */
    public function createToken(VoiceServer $server, PermissionSet $permissions, VoiceQueryEngine $engine): array;
}
