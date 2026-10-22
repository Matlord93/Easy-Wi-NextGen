<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use Symfony\Component\Yaml\Yaml;

final class MinecraftConfigurationPolicy
{
    public static function assertEditable(Instance $instance, string $path, string $previous, string $content): void
    {
        $resolver = $instance->getTemplate()->getInstallResolver()['type'] ?? '';
        if (!in_array($resolver, ['minecraft_vanilla', 'papermc_paper', 'minecraft_spigot', 'minecraft_bedrock', 'minecraft_bungeecord'], true)) {
            return;
        }
        if ('server.properties' === basename($path)) {
            $managed = ['max-players', 'server-port', 'server-portv6', 'server-ip', 'query.port', 'rcon.port', 'enable-query', 'enable-rcon', 'transport', 'enable-lan-visibility', 'server-udp-ports'];
            $read = static function (string $text) use ($managed): array {
                $values = [];
                foreach (preg_split('/\R/', $text) ?: [] as $line) {
                    if (preg_match('/^\s*([^#!\s=:]+)\s*[=:]\s*(.*)$/', $line, $match) && in_array(strtolower($match[1]), $managed, true)) {
                        $values[strtolower($match[1])] = trim($match[2]);
                    }
                }
                ksort($values);

                return $values;
            };
            if ($read($previous) !== $read($content)) {
                throw new \InvalidArgumentException('Network ports and bind settings are managed by the panel.');
            }
        }
        if ('minecraft_bungeecord' === $resolver && 'config.yml' === basename($path)) {
            try {
                $before = Yaml::parse($previous) ?? [];
                $after = Yaml::parse($content) ?? [];
            } catch (\Throwable) {
                throw new \InvalidArgumentException('Invalid BungeeCord YAML configuration.');
            }
            $network = static function (array $data): array {
                $listeners = $data['listeners'] ?? [];
                if (!is_array($listeners)) {
                    throw new \InvalidArgumentException('Proxy listeners must be a list.');
                }

                return array_map(static function (mixed $listener): array {
                    if (!is_array($listener)) {
                        throw new \InvalidArgumentException('Proxy listener must be a mapping.');
                    }

                    return array_intersect_key($listener, array_flip(['host', 'query_port', 'query_enabled']));
                }, $listeners);
            };
            if (!is_array($before) || !is_array($after) || $network($before) !== $network($after)) {
                throw new \InvalidArgumentException('Proxy listeners and ports are managed by the panel.');
            }
        }
    }
}
