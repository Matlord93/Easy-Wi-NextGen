<?php

declare(strict_types=1);

namespace App\Module\Voice\Application;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Voice\Application\Provider\VoiceQueryResult;

interface TeamspeakServerOperationsInterface
{
    public function resolve(VoiceInstance $instance): Ts3VirtualServer|Ts6VirtualServer;

    public function action(Ts3VirtualServer|Ts6VirtualServer $server, string $action): AgentJob;

    public function activeAction(Ts3VirtualServer|Ts6VirtualServer $server): ?AgentJob;

    public function probe(Ts3VirtualServer|Ts6VirtualServer $server): VoiceQueryResult;
}
