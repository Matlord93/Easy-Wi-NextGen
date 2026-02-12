<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

use App\Module\Core\Domain\Entity\VoiceInstance;

final class Teamspeak3Adapter implements VoiceProviderAdapter
{
    public function __construct(private readonly Ts3ServerLookupInterface $lookup)
    {
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'ts3';
    }

    public function query(VoiceInstance $instance): VoiceQueryResult
    {
        $result = $this->probeStatus($instance);

        return new VoiceQueryResult(
            (string) $result['status'],
            is_numeric($result['players_online'] ?? null) ? (int) $result['players_online'] : null,
            is_numeric($result['players_max'] ?? null) ? (int) $result['players_max'] : null,
            null,
            new \DateTimeImmutable(),
            $result['reason'] ?? null,
            $result['error_code'] ?? null,
        );
    }

    public function probeStatus(VoiceInstance $instance): array
    {
        $server = $this->lookup->find($instance->getExternalId());
        if ($server === null) {
            return ['status' => 'unknown', 'players_online' => null, 'players_max' => null, 'reason' => 'Server not found.', 'error_code' => 'voice_instance_not_found'];
        }

        return ['status' => $server['status'], 'players_online' => null, 'players_max' => null, 'reason' => null, 'error_code' => null];
    }

    public function performAction(VoiceInstance $instance, string $action): array
    {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return ['accepted' => false, 'reason' => 'Action invalid.', 'error_code' => 'voice_action_invalid'];
        }

        return ['accepted' => true, 'reason' => null, 'error_code' => null];
    }

    public function getConnectInfo(VoiceInstance $instance): array
    {
        $server = $this->lookup->find($instance->getExternalId());
        return [
            'host' => $server['public_host'] ?? $instance->getNode()->getHost(),
            'port' => $server['voice_port'] ?? null,
        ];
    }

    public function getPlayers(VoiceInstance $instance): array
    {
        return ['online' => null, 'max' => null];
    }
}
