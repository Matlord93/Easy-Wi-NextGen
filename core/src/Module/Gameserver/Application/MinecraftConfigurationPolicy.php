<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use App\Module\Gameserver\Application\Configuration\GameConfigurationFields;
use App\Module\Gameserver\Application\Configuration\GameSetupProfiles;
use Symfony\Component\Yaml\Yaml;

final class MinecraftConfigurationPolicy
{
    public static function assertEditable(Instance $instance, string $path, string $previous, string $content, bool $allowSlotChange = false): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $format = GameSetupProfiles::format($instance->getTemplate()->getGameKey(), $path) ?? match ($extension) {'cfg', 'conf' => 'convar', default => $extension};
        if (in_array($format, ['properties','ini','convar','json','jsonc','yaml','yml','xml','unreal','assignment','flat','fivem'], true)) {
            $fixed = static function (string $text, bool $validateSlots) use ($format, $allowSlotChange, $instance, $path): array {
                $values = [];
                try {
                    $parsed = GameConfigDocument::values($text, $format, true);
                } catch (\JsonException|\Symfony\Component\Yaml\Exception\ParseException $exception) {
                    throw new \InvalidArgumentException('Die Konfigurationsdatei enthält ungültiges JSON oder YAML.', 0, $exception);
                }
                foreach ($parsed as $key => $value) {
                    $profileManaged = GameSetupProfiles::managed($instance->getTemplate()->getGameKey(), $path, $key, $instance->getTemplate());
                    if (GameConfigurationFields::slots($key) && $allowSlotChange && !$profileManaged) {
                        $slots = filter_var($value, FILTER_VALIDATE_INT);
                        if ($validateSlots && (!is_int($slots) || $slots < 1 || $slots > $instance->getMaxSlots() || ($instance->isLockSlots() && $slots !== $instance->getCurrentSlots()))) {
                            throw new \InvalidArgumentException('Das Spielerlimit muss innerhalb der gebuchten Slots liegen.');
                        }
                    } elseif (GameConfigurationFields::managed($key) || $profileManaged || GameConfigurationFields::slots($key)) {
                        $values[$key] = $value;
                    }
                }
                ksort($values);
                return $values;
            };
            if ($fixed($previous, false) !== $fixed($content, true)) {
                throw new \InvalidArgumentException('IP-Adresse, Ports und gebuchte Ressourcen sind fest. Das Spielerlimit kann unter Setup innerhalb der gebuchten Slots geändert werden.');
            }
        }
        \App\Module\Gameserver\Application\Configuration\VRisingHostConfiguration::assertEditable($instance, $path, $previous, $content, $allowSlotChange);
        \App\Module\Gameserver\Application\Configuration\VRisingGameConfiguration::assertEditable($instance, $path, $previous, $content);
        $resolver = $instance->getTemplate()->getInstallResolver()['type'] ?? '';
        if (!in_array($resolver, ['minecraft_vanilla', 'papermc_paper', 'minecraft_spigot', 'minecraft_bedrock', 'minecraft_bungeecord'], true)) {
            return;
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
