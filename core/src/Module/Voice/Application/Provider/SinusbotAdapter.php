<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

use App\Module\Core\Domain\Entity\VoiceInstance;

final class SinusbotAdapter implements VoiceProviderAdapter
{
    #[\Override]
    public function supports(string $providerType): bool
    {
        return $providerType === 'sinusbot';
    }

    #[\Override]
    public function query(VoiceInstance $instance): VoiceQueryResult
    {
        return new VoiceQueryResult(
            'unknown',
            null,
            null,
            null,
            new \DateTimeImmutable(),
            'SinusBot query is not available in unified voice module.',
            'voice_query_not_supported',
        );
    }

    /**
     * @return array{status: string, players_online: null, players_max: null, reason: string, error_code:
     * string}
     */
    #[\Override]
    public function probeStatus(VoiceInstance $instance): array
    {
        return [
            'status' => 'unknown',
            'players_online' => null,
            'players_max' => null,
            'reason' => 'SinusBot query is not available in unified voice module.',
            'error_code' => 'voice_query_not_supported',
        ];
    }

    /** @return array{accepted: bool, reason: string, error_code: string} */
    #[\Override]
    public function performAction(VoiceInstance $instance, string $action): array
    {
        return ['accepted' => false, 'reason' => 'SinusBot actions are not supported in this endpoint.', 'error_code' => 'voice_action_not_supported'];
    }

    /** @return array{host: string, port: null} */
    #[\Override]
    public function getConnectInfo(VoiceInstance $instance): array
    {
        return [
            'host' => $instance->getNode()->getHost(),
            'port' => null,
        ];
    }

    /** @return array{online: null, max: null} */
    #[\Override]
    public function getPlayers(VoiceInstance $instance): array
    {
        return ['online' => null, 'max' => null];
    }
}
