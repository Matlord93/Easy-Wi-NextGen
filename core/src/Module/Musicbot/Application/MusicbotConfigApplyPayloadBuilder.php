<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

final class MusicbotConfigApplyPayloadBuilder
{
    public function __construct(
        private readonly MusicbotRuntimeConfigBuilder $runtimeConfigBuilder,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(MusicbotInstance $instance): array
    {
        $config = $this->runtimeConfigBuilder->build($instance);
        $teamspeak = is_array($config['teamspeak'] ?? null) ? $config['teamspeak'] : [];

        $profile = $this->nonEmptyString($teamspeak['profile'] ?? null, 'ts3');
        $backendType = $this->normalizeBackendType($teamspeak['backend_type'] ?? null);
        $host = $this->nonEmptyString($teamspeak['host'] ?? null, 'localhost');
        $port = $this->normalizePort($teamspeak['port'] ?? null);
        $nickname = $this->nonEmptyString($teamspeak['nickname'] ?? null, 'Musicbot');
        $channelId = (string) ($teamspeak['channel_id'] ?? '');
        $channelName = (string) ($teamspeak['channel_name'] ?? '');
        $commandPrefix = mb_substr($this->nonEmptyString($teamspeak['command_prefix'] ?? $instance->getInstanceConfig()['command_prefix'] ?? null, '!'), 0, 5) ?: '!';
        $audioBackend = $this->nonEmptyString($teamspeak['audio_backend'] ?? null, 'default');
        $backendPath = (string) ($teamspeak['backend_path'] ?? $teamspeak['bridge_path'] ?? $teamspeak['binary_path'] ?? '');
        $installPath = $this->nonEmptyString($config['install_path'] ?? null, $instance->getInstallPath());
        $clientBinaryPath = (string) ($teamspeak['client_binary_path'] ?? $teamspeak['binary_path'] ?? '');
        $clientRunscriptPath = (string) ($teamspeak['client_runscript_path'] ?? '');

        $config['teamspeak'] = array_replace($teamspeak, [
            'enabled' => (bool) ($teamspeak['enabled'] ?? false),
            'platform' => 'teamspeak',
            'profile' => $profile,
            'backend_type' => $backendType,
            'host' => $host,
            'port' => $port,
            'nickname' => $nickname,
            'channel_id' => $channelId,
            'channel_name' => $channelName,
            'command_prefix' => $commandPrefix,
            'audio_backend' => $audioBackend,
            'backend_path' => $backendPath,
            'install_path' => $installPath,
            'ts3_client_binary_path' => $clientBinaryPath,
            'ts3_runscript_path' => $clientRunscriptPath,
        ]);

        return [
            'instance_id' => (string) $instance->getId(),
            'service_name' => $instance->getServiceName(),
            'install_path' => $installPath,
            'config_file_permissions' => '0600',
            'platform' => 'teamspeak',
            'profile' => $profile,
            'backend_type' => $backendType,
            'host' => $host,
            'port' => $port,
            'nickname' => $nickname,
            'channel_id' => $channelId,
            'channel_name' => $channelName,
            'command_prefix' => $commandPrefix,
            'audio_backend' => $audioBackend,
            'backend_path' => $backendPath,
            'ts3_client_binary_path' => $clientBinaryPath,
            'ts3_runscript_path' => $clientRunscriptPath,
            'config' => $config,
        ];
    }

    private function normalizeBackendType(mixed $value): string
    {
        $backendType = is_string($value) ? trim($value) : '';

        return in_array($backendType, ['placeholder', 'client_library', 'native_sdk', 'external_client_bridge', 'disabled'], true)
            ? $backendType
            : 'placeholder';
    }

    private function normalizePort(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 9987;
        }

        return max(1, min(65535, (int) $value));
    }

    private function nonEmptyString(mixed $value, string $default): string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : $default;
    }
}
