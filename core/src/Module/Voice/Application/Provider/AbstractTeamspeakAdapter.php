<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Voice\Application\TeamspeakServerOperationsInterface;
use App\Module\Voice\Application\VoiceActionConflict;

abstract class AbstractTeamspeakAdapter implements VoiceProviderAdapter
{
    public function __construct(private readonly TeamspeakServerOperationsInterface $operations)
    {
    }

    #[\Override]
    public function query(VoiceInstance $instance): VoiceQueryResult
    {
        try {
            return $this->operations->probe($this->operations->resolve($instance));
        } catch (\RuntimeException) {
            return new VoiceQueryResult('unknown', null, null, null, new \DateTimeImmutable(), 'Server not found or inaccessible.', 'voice_instance_not_found');
        }
    }

    #[\Override]
    public function probeStatus(VoiceInstance $instance): array
    {
        $result = $this->query($instance);
        return ['status' => $result->status, 'players_online' => $result->players, 'players_max' => $result->maxPlayers, 'reason' => $result->reason, 'error_code' => $result->errorCode, 'job_id' => $result->jobId, 'checked_at' => $result->checkedAt];
    }

    /** @return array{accepted:bool,reason:?string,error_code:?string,job_id:?string} */
    #[\Override]
    public function performAction(VoiceInstance $instance, string $action): array
    {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return ['accepted' => false, 'reason' => 'Action invalid.', 'error_code' => 'voice_action_invalid', 'job_id' => null];
        }
        try {
            $job = $this->operations->action($this->operations->resolve($instance), $action);
        } catch (VoiceActionConflict $conflict) {
            return ['accepted' => false, 'reason' => $conflict->getMessage(), 'error_code' => 'voice_action_in_progress', 'job_id' => $conflict->jobId];
        } catch (\RuntimeException) {
            return ['accepted' => false, 'reason' => 'Server not found or inaccessible.', 'error_code' => 'voice_instance_not_found', 'job_id' => null];
        }

        $instance->invalidateStatus();
        return ['accepted' => true, 'reason' => null, 'error_code' => null, 'job_id' => $job->getId()];
    }

    #[\Override]
    public function getConnectInfo(VoiceInstance $instance): array
    {
        try {
            $server = $this->operations->resolve($instance);
        } catch (\RuntimeException) {
            return ['host' => null, 'port' => null];
        }
        $nodeHost = $instance->getNode()->getHost();
        $nodeHost = $this->publicHost($nodeHost);

        return [
            'host' => $server->getPublicHost() ?? $this->publicHost($server->getNode()->getAgent()->getLastHeartbeatIp()) ?? $nodeHost,
            'port' => $server->getVoicePort(),
        ];
    }


    private function publicHost(?string $host): ?string
    {
        return $host === null || str_starts_with($host, '127.') || $host === '::1' || $host === 'localhost' ? null : $host;
    }
}
