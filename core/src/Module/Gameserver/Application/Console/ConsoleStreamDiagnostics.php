<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

final class ConsoleStreamDiagnostics
{
    public function __construct(
        private readonly ConsoleAgentGrpcClientInterface $grpcClient,
        private readonly AgentEndpointProbeInterface $agentEndpointProbe,
        private readonly ?\Redis $redis = null,
        private readonly int $relayStaleAfterSeconds = 20,
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $heartbeatAge = $this->relayHeartbeatAgeSeconds();

        return [
            'active_grpc_client_class' => $this->grpcClient::class,
            'backend_configured' => !$this->isNullClient(),
            'redis_ping_ok' => $this->redisPingOk(),
            'relay_heartbeat_age_seconds' => $heartbeatAge,
            'relay_stale' => $heartbeatAge === null ? true : $heartbeatAge > $this->relayStaleAfterSeconds,
            'sample_node_endpoint_present' => $this->sampleNodeEndpointPresent(),
        ];
    }

    public function isNullClient(): bool
    {
        return str_contains($this->grpcClient::class, 'NullConsoleAgentGrpcClient');
    }

    public function redisPingOk(): bool
    {
        if (!$this->redis instanceof \Redis) {
            return true;
        }

        try {
            $pong = $this->redis->ping();
        } catch (\Throwable) {
            return false;
        }

        return $pong === true || strtoupper((string) $pong) === 'PONG';
    }

    public function relayHeartbeatAgeSeconds(): ?int
    {
        if (!$this->redis instanceof \Redis) {
            return null;
        }

        try {
            $value = $this->redis->get('console_relay:heartbeat');
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return max(0, time() - (int) $value);
    }

    public function sampleNodeEndpointPresent(): bool
    {
        return $this->agentEndpointProbe->hasAnyEndpoint();
    }
}
