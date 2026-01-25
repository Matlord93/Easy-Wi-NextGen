<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\Application;

final class AgentJobValidator
{
    /**
     * @return string[]
     */
    public function validate(string $type, array $payload): array
    {
        return match ($type) {
            'ts3.install', 'ts6.install' => $this->requireKeys($payload, ['node_id', 'install_dir', 'service_name']),
            'sinusbot.install' => $this->requireKeys($payload, ['node_id', 'install_dir', 'service_name']),
            'ts3.instance.create' => $this->requireKeys($payload, ['instance_id', 'voice_port', 'query_port', 'file_port', 'db_mode']),
            'ts6.instance.create' => $this->requireKeys($payload, ['instance_id', 'name']),
            'sinusbot.instance.create' => $this->requireKeys($payload, ['instance_id', 'data_dir']),
            'ts3.instance.action', 'ts6.instance.action', 'sinusbot.instance.action' => $this->requireKeys($payload, ['instance_id', 'action']),
            'ts3.service.action', 'ts6.service.action', 'sinusbot.service.action' => $this->requireKeys($payload, ['action']),
            'ts3.virtual.create', 'ts6.virtual.create' => $this->requireKeys($payload, ['virtual_server_id', 'name']),
            'ts3.virtual.action', 'ts6.virtual.action' => $this->requireKeys($payload, ['virtual_server_id', 'action']),
            'ts3.virtual.token.rotate', 'ts6.virtual.token.rotate' => $this->requireKeys($payload, ['virtual_server_id']),
            'ts3.viewer.snapshot', 'ts6.viewer.snapshot' => $this->requireKeys($payload, ['virtual_server_id', 'cache_key']),
            'admin.ssh_key.store' => $this->requireKeys($payload, ['user_id', 'authorized_keys_path', 'public_key']),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $keys
     * @return string[]
     */
    private function requireKeys(array $payload, array $keys): array
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                $missing[] = sprintf('Missing required field: %s', $key);
            }
        }

        return $missing;
    }
}
