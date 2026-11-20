<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;

/** Built-in descriptions are scoped to a game and a real configuration file. */
final class GameSetupProfiles
{
    /** @return list<string> */
    public static function configurationPaths(string $gameKey, string $path): array
    {
        if (preg_match('/^satisfactory(?:_windows)?$/D', $gameKey) && preg_match('#^FactoryGame/Saved/Config/(?:LinuxServer|WindowsServer)/ServerSettings[.]ini$#D', $path)) {
            $directory = dirname($path);

            return [$directory.'/Game.ini', $directory.'/Engine.ini'];
        }

        if (preg_match('/^v_rising(?:_windows)?$/D', $gameKey) && 'save-data/Settings/ServerHostSettings.json' === $path) {
            return [$path, 'save-data/Settings/ServerGameSettings.json'];
        }

        return [$path];
    }

    public static function hasNativeStartup(Template $template): bool
    {
        return SourceServerSetup::supports($template) || ValheimServerSetup::supports($template);
    }

    /** @return array<string,array{label:string,type:string,required:bool,scope:string,validation:array<string,mixed>,helptext:string}> */
    public static function nativeStartupFields(Template $template): array
    {
        return array_replace(SourceServerSetup::fields($template), ValheimServerSetup::fields($template));
    }

    /** @return array<string,string> */
    public static function startupDefaults(Instance $instance): array
    {
        return array_replace(SourceServerSetup::defaults($instance), ValheimServerSetup::defaults($instance));
    }

    public static function startupParameters(Instance $instance): string
    {
        $parameters = SourceServerSetup::parameters($instance);
        $parameters = ValheimServerSetup::parameters($instance, $parameters);
        $parameters = VRisingHostConfiguration::parameters($instance, $parameters);

        return self::startParams($instance->getTemplate()->getGameKey(), $parameters, $instance->getCurrentSlots());
    }

    /** @var array<string,mixed>|null */
    private static ?array $catalog = null;

    /** @return array<string,mixed> */
    private static function profile(string $gameKey): array
    {
        if (null === self::$catalog) {
            $content = file_get_contents(dirname(__DIR__, 5).'/config/gameserver/setup_profiles.json');
            if (false === $content) {
                throw new \RuntimeException('Die Spielprofile konnten nicht geladen werden.');
            }
            self::$catalog = JsonMap::fromValue(json_decode($content, true, 64, JSON_THROW_ON_ERROR)) ?? [];
        }
        $aliases = JsonMap::strings(JsonMap::fromValue(self::$catalog['aliases'] ?? null) ?? []);
        $profiles = JsonMap::fromValue(self::$catalog['profiles'] ?? null) ?? [];
        $base = preg_replace('/_windows$/D', '', strtolower($gameKey)) ?? strtolower($gameKey);

        return JsonMap::fromValue($profiles[$aliases[$base] ?? $base] ?? null) ?? [];
    }

    /** @return array{title:string,description:string,startup_only:bool} */
    public static function summary(string $gameKey): array
    {
        $profile = self::profile($gameKey);
        $text = JsonMap::strings($profile);

        $titles = JsonMap::strings(JsonMap::fromValue(self::$catalog['titles'] ?? null) ?? []);
        $base = preg_replace('/_windows$/D', '', strtolower($gameKey)) ?? strtolower($gameKey);

        return ['title' => $titles[$base] ?? $text['title'] ?? $gameKey, 'description' => $text['description'] ?? 'Einstellungen aus den am Spieltemplate hinterlegten Dateien.', 'startup_only' => true === ($profile['startup_only'] ?? false)];
    }

    /** @return array<string,mixed> */
    public static function file(string $gameKey, string $path): array
    {
        $files = JsonMap::fromValue(self::profile($gameKey)['files'] ?? null) ?? [];
        $name = strtolower(basename(str_replace('\\', '/', $path)));
        if (!isset($files[$name]) && isset($files['server.cfg']) && preg_match('/^gamemode_[a-z0-9_]+[.]cfg$/D', $name) && in_array(preg_replace('/_windows$/D', '', strtolower($gameKey)), ['cs2', 'csgo_legacy'], true)) {
            $name = 'server.cfg';
        }

        return JsonMap::fromValue($files[$name] ?? null) ?? [];
    }

    public static function format(string $gameKey, string $path): ?string
    {
        return JsonMap::strings(self::file($gameKey, $path))['format'] ?? null;
    }

    /** @return array<string,mixed> */
    public static function field(string $gameKey, string $path, string $key): array
    {
        $fields = JsonMap::fromValue(self::file($gameKey, $path)['fields'] ?? null) ?? [];
        $key = strtolower($key);
        $tail = preg_replace('/^.*[.]/', '', $key) ?? $key;
        $indexed = preg_replace('/(?<=[.])[0-9]+(?=[.]|$)/', '*', $key) ?? $key;

        return JsonMap::fromValue($fields[$key] ?? $fields[$indexed] ?? $fields[$tail] ?? null) ?? [];
    }

    /** @return array<string,mixed> */
    public static function startup(string $gameKey, string $key): array
    {
        $fields = JsonMap::fromValue(self::profile($gameKey)['startup_fields'] ?? null) ?? [];

        return JsonMap::fromValue($fields[$key] ?? null) ?? [];
    }

    /** Keep legacy hard-coded slot arguments consistent with the saved player limit. */
    public static function startParams(string $gameKey, string $parameters, int $slots): string
    {
        $base = preg_replace('/_windows$/D', '', strtolower($gameKey)) ?? strtolower($gameKey);
        $pattern = match ($base) {
            'rust' => '/(\+server[.]maxplayers\s+)(?:[0-9]+|\{\{MAX_PLAYERS\}\})/D',
            'ark' => '/([?&]MaxPlayers=)(?:[0-9]+|\{\{MAX_PLAYERS\}\})/i',
            default => null,
        };
        if (null === $pattern) {
            return $parameters;
        }

        return preg_replace_callback($pattern, static fn (array $match): string => $match[1].$slots, $parameters) ?? $parameters;
    }

    public static function isAdminOnly(Template $template, string $variable): bool
    {
        if (in_array(strtolower($template->getGameKey()), ['v_rising', 'v_rising_windows'], true) && (str_starts_with(strtoupper($variable), 'VR_RCON_') || in_array(strtoupper($variable), ['ADDRESS', 'VR_ADDRESS', 'VR_BIND_ADDRESS', 'VR_MAX_USERS', 'VR_MAX_ADMINS'], true))) {
            return true;
        }
        if ('' === $variable) {
            return false;
        }
        $requirements = array_merge($template->getRequirementVars(), $template->getRequirementSecrets());
        foreach (array_keys($requirements) as $index) {
            $text = JsonMap::strings($requirements[$index]);
            if (trim($text['key'] ?? '') === $variable && 'admin_only' === strtolower(trim($text['scope'] ?? 'customer_allowed'))) {
                return true;
            }
        }

        return false;
    }

    public static function managed(string $gameKey, string $path, string $key, ?Template $template = null): bool
    {
        $field = self::field($gameKey, $path, $key);
        if (true === ($field['readonly'] ?? false)) {
            return true;
        }
        if (null !== $template) {
            if ('serverhostsettings.json' === strtolower(basename(str_replace('\\', '/', $path))) && in_array(strtolower($key), ['maxconnectedusers', 'maxconnectedadmins'], true) && VRisingHostConfiguration::hasCapacityOverride($template)) {
                return true;
            }
            $variable = JsonMap::strings($field)['startup_var'] ?? '';
            if (self::isAdminOnly($template, $variable)) {
                return true;
            }
            if (GameConfigurationFields::slots($key)) {
                foreach (['MAX_PLAYERS', 'MAXPLAYERS', 'SLOTS', 'SV_MAXPLAYERS'] as $slotVariable) {
                    if (self::isAdminOnly($template, $slotVariable)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
