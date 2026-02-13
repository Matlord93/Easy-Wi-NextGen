<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

use App\Module\Core\Domain\Entity\VoiceInstance;

interface VoiceProviderAdapter extends VoiceProviderInterface
{
    public function query(VoiceInstance $instance): VoiceQueryResult;

    /**
     * @return array{status:string,players_online:?int,players_max:?int,reason:?string,error_code:?string}
     */
    public function probeStatus(VoiceInstance $instance): array;

    /**
     * @return array{accepted:bool,reason:?string,error_code:?string}
     */
    public function performAction(VoiceInstance $instance, string $action): array;

    /** @return array<string,mixed> */
    public function getConnectInfo(VoiceInstance $instance): array;

    /** @return array{online:?int,max:?int} */
    public function getPlayers(VoiceInstance $instance): array;
}
