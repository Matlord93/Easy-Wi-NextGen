<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;

/** Native Source launch settings. Custom launch wrappers keep their own arguments. */
final class SourceServerSetup
{
    private const GAMES = ['cs2', 'csgo_legacy', 'css', 'tf2', 'hl2dm', 'l4d', 'l4d2', 'dods', 'garrys_mod'];

    private static function game(Template $template): string
    {
        return preg_replace('/_windows$/D', '', strtolower($template->getGameKey())) ?? strtolower($template->getGameKey());
    }

    public static function supports(Template $template): bool
    {
        if (!in_array(self::game($template), self::GAMES, true) || !preg_match('/^\s*(?:"([^"]+)"|(\S+))/', $template->getStartParams(), $match)) {
            return false;
        }
        $command = $match[1] ?? '';
        if ('' === $command) {
            $command = $match[2] ?? '';
        }
        $binary = strtolower(basename(str_replace('\\', '/', $command)));

        return in_array($binary, ['srcds_run', 'srcds_linux', 'srcds_linux64', 'srcds.exe', 'srcds_win64.exe', 'srcds', 'cs2.sh', 'cs2.exe', 'cs2'], true);
    }

    public static function canEditConvar(Template $template, string $key): bool
    {
        if (!in_array(self::game($template), self::GAMES, true)) {
            return true;
        }
        if (self::commandDirective($key)) {
            return false;
        }
        if (self::supports($template) && GameConfigurationFields::slots($key)) {
            return false;
        }

        return !self::source2($template) || !in_array(strtolower($key), ['tickrate', 'sv_alltalk'], true);
    }

    public static function source2(Template $template): bool
    {
        return 'cs2' === self::game($template);
    }

    /** @return array<string,string> */
    public static function optionalArguments(Template $template): array
    {
        $arguments = self::optionalArgumentsForGame($template->getGameKey());
        // Other Source branches may have administrator-configured Steam login.
        // Keep that integration without adding unverified flags to fresh seeds.
        if (preg_match('/(?:^|\s)\+sv_setsteamaccount\s+/', $template->getStartParams())) {
            $arguments['STEAM_GSLT'] = '+sv_setsteamaccount';
        }

        return $arguments;
    }

    /** @return array<string,string> */
    public static function optionalArgumentsForGame(string $gameKey): array
    {
        return match (preg_replace('/_windows$/D', '', strtolower($gameKey))) {
            'cs2', 'csgo_legacy' => ['STEAM_GSLT' => '+sv_setsteamaccount', 'AUTHKEY' => '-authkey', 'WORKSHOP_COLLECTION' => '+host_workshop_collection', 'WORKSHOP_MAP' => '+host_workshop_map'],
            'garrys_mod' => ['STEAM_GSLT' => '+sv_setsteamaccount', 'WORKSHOP_COLLECTION' => '+host_workshop_collection', 'GAMEMODE' => '+gamemode'],
            'tf2', 'css' => ['STEAM_GSLT' => '+sv_setsteamaccount'],
            default => [],
        };
    }

    /** @return array<string,array{label:string,type:string,required:bool,scope:string,validation:array<string,mixed>,helptext:string}> */
    public static function fields(Template $template): array
    {
        if (!self::supports($template)) {
            return [];
        }
        $fields = [
            'MAX_PLAYERS' => ['label' => 'Maximale Spielerzahl', 'type' => 'number', 'required' => true, 'scope' => 'customer_allowed', 'validation' => ['pattern' => '/^[1-9][0-9]*$/D'], 'helptext' => 'Aktuelles Spielerlimit innerhalb der gebuchten Slots. Die Engine kann zusätzliche eigene Grenzen haben.'],
            'MAP' => ['label' => 'Startkarte', 'type' => 'text', 'required' => true, 'scope' => 'customer_allowed', 'validation' => ['pattern' => '/^(?!.*[.][.])[A-Za-z0-9][A-Za-z0-9_.\/-]{0,127}$/D'], 'helptext' => 'Installierter Kartenname ohne Dateiendung, z. B. de_dust2. Workshop-Karten können einen workshop/...-Pfad verwenden.'],
        ];
        if (in_array(self::game($template), ['cs2', 'csgo_legacy'], true)) {
            foreach (['GAME_TYPE' => 'Spieltyp', 'GAME_MODE' => 'Spielmodus'] as $key => $label) {
                $fields[$key] = ['label' => $label, 'type' => 'number', 'required' => false, 'scope' => 'customer_allowed', 'validation' => ['pattern' => '/^(?:0|[1-9][0-9]*)$/D', 'min' => 0], 'helptext' => 'Counter-Strike-Spieltyp bzw. Modus. Klassisch: Typ 0, Casual-Modus 0, Competitive-Modus 1. Leer lässt die bestehende Spielkonfiguration maßgeblich.'];
            }
        }
        if (!self::source2($template) && preg_match('/(?:^|\s)-tickrate\s+/i', $template->getStartParams())) {
            $fields['TICKRATE'] = ['label' => 'Tickrate', 'type' => 'number', 'required' => false, 'scope' => 'customer_allowed', 'validation' => ['pattern' => '/^[1-9][0-9]*$/D'], 'helptext' => 'Start-Tickrate des dafür konfigurierten Source-1-Servers. Unterstützung und wirksamer Wert hängen vom Spiel und seinen Erweiterungen ab.'];
        }
        foreach (array_keys(self::optionalArguments($template)) as $key) {
            $secret = in_array($key, ['STEAM_GSLT', 'AUTHKEY'], true);
            $label = match ($key) {
                'STEAM_GSLT' => 'Steam-Server-Token (GSLT)', 'AUTHKEY' => 'Steam Web-API-Schlüssel (Workshop)', 'WORKSHOP_MAP' => 'Workshop-Karten-ID', 'WORKSHOP_COLLECTION' => 'Workshop-Sammlungs-ID', default => 'Garry’s-Mod-Spielmodus',
            };
            $fields[$key] = ['label' => $label, 'type' => $secret ? 'password' : 'text', 'required' => false, 'scope' => 'customer_allowed', 'validation' => ['pattern' => $secret ? '/^[a-fA-F0-9]{32}$/D' : ('GAMEMODE' === $key ? '/^[A-Za-z0-9_-]{1,64}$/D' : '/^[1-9][0-9]{0,19}$/D')], 'helptext' => match ($key) {
                'STEAM_GSLT' => match (self::game($template)) {
                    'garrys_mod' => 'Eigener Steam Game Server Login Token für App-ID 4000. Für die Garry’s-Mod-Serverliste vorgesehen; jeder Server benötigt einen eigenen Token. Kein Steam-Web-API-Schlüssel.',
                    'cs2', 'csgo_legacy' => 'Steam Game Server Login Token für App-ID 730 zur öffentlichen Serveranmeldung. Jeder Server benötigt einen eigenen Token. Kein Steam-Web-API-Schlüssel.',
                    'tf2' => 'Optionaler Steam Game Server Login Token für App-ID 440. Jeder Server benötigt einen eigenen Token. Kein Steam-Web-API-Schlüssel.',
                    'css' => 'Optionaler Steam Game Server Login Token für App-ID 240. Jeder Server benötigt einen eigenen Token. Kein Steam-Web-API-Schlüssel.',
                    default => 'Bereits vom Betreiber eingerichteter Steam Game Server Login Token. Zur App-ID dieses Spiels ausstellen und pro Server nur einmal verwenden; kein Steam-Web-API-Schlüssel.',
                },
                'AUTHKEY' => 'Optionaler Steam-Web-API-Schlüssel für die Counter-Strike-Workshop-Anbindung. Nur bei eingerichtetem Workshop ausgegeben; getrennt vom GSLT.',
                'WORKSHOP_MAP' => 'Numerische Steam-Workshop-Karten-ID. Bei einer Sammlung die Startkarte daraus; ohne Sammlung eine einzelne Workshop-Karte. Leer startet die normale Startkarte.',
                'WORKSHOP_COLLECTION' => 'garrys_mod' === self::game($template) ? 'Optionale öffentliche oder nicht gelistete Steam-Workshop-Sammlung. Ihre Addons werden beim Start eingebunden.' : 'Optionale Steam-Workshop-Sammlung. Die Workshop-Karten-ID legt die Startkarte aus dieser Sammlung fest.',
                default => 'Ordnername eines installierten Garry’s-Mod-Spielmodus, z. B. sandbox. Leer erhält die vorhandene Vorgabe.',
            }];
        }

        return $fields;
    }

    private static function argument(string $parameters, string $name): ?string
    {
        if (preg_match('/(?:^|\s)'.preg_quote($name, '/').'\s+(?:"([^"]*)"|([^\s+]+))/', $parameters, $match)) {
            $value = '' !== ($match[1] ?? '') ? $match[1] : ($match[2] ?? '');

            return str_contains($value, '{{') ? null : $value;
        }

        return null;
    }

    /** @return array<string,string> */
    public static function defaults(Instance $instance): array
    {
        $template = $instance->getTemplate();
        if (!self::supports($template)) {
            return [];
        }
        $defaults = ['MAX_PLAYERS' => (string) $instance->getCurrentSlots(), 'MAP' => self::argument($template->getStartParams(), '+map') ?? ''];
        foreach (['GAME_TYPE' => '+game_type', 'GAME_MODE' => '+game_mode', 'TICKRATE' => '-tickrate'] as $key => $argument) {
            $defaults[$key] = self::argument($template->getStartParams(), $argument) ?? '';
        }
        foreach (self::optionalArguments($template) as $key => $argument) {
            $defaults[$key] = self::argument($template->getStartParams(), $argument) ?? '';
        }
        if (array_key_exists('WORKSHOP_MAP', $defaults) && '' === $defaults['WORKSHOP_MAP']) {
            $defaults['WORKSHOP_MAP'] = self::argument($template->getStartParams(), '+workshop_start_map') ?? '';
        }
        $environment = $template->getEnvVars();
        $optionalArguments = self::optionalArguments($template);
        foreach (array_keys($environment) as $index) {
            $text = JsonMap::strings($environment[$index]);
            if (isset($text['key'], $text['value']) && array_key_exists($text['key'], $defaults)) {
                if (isset($optionalArguments[$text['key']]) && '' !== $defaults[$text['key']]) {
                    continue;
                }
                $defaults[$text['key']] = $text['value'];
            }
        }
        $defaults['MAX_PLAYERS'] = (string) $instance->getCurrentSlots();
        $gslt = $instance->getGslKey();
        if (array_key_exists('STEAM_GSLT', $defaults) && null !== $gslt) {
            $defaults['STEAM_GSLT'] = $gslt;
        }

        return $defaults;
    }

    private static function bind(string $parameters, string $argument, string $variable, bool $append): string
    {
        $pattern = '/(^|\s)'.preg_quote($argument, '/').'\s+(?:"[^"\r\n]*"|[^\s]+)/';
        $matched = 1 === preg_match($pattern, $parameters);
        $bound = preg_replace_callback($pattern, static fn (array $match): string => $match[1].$argument.' {{'.$variable.'}}', $parameters) ?? $parameters;

        return !$matched && $append ? rtrim($bound).' '.$argument.' {{'.$variable.'}}' : $bound;
    }

    private static function removeArgument(string $parameters, string $argument): string
    {
        return preg_replace('/(?:^|\s)'.preg_quote($argument, '/').'\s+(?:"[^"\r\n]*"|[^\s+-][^\s]*)(?=\s|$)/i', '', $parameters) ?? $parameters;
    }

    public static function parameters(Instance $instance): string
    {
        $template = $instance->getTemplate();
        $parameters = $template->getStartParams();
        if (!self::supports($template)) {
            return $parameters;
        }
        if (self::source2($template)) {
            $parameters = preg_replace('/(?:^|\s)-tickrate\s+(?:"[^"\r\n]*"|[^\s]+)/i', '', $parameters) ?? $parameters;
        }
        $slotArgument = match (self::game($template)) {
            'cs2' => '-maxplayers', 'csgo_legacy' => '-maxplayers_override', default => '+maxplayers',
        };
        $parameters = self::bind($parameters, $slotArgument, 'MAX_PLAYERS', true);
        $parameters = self::bind($parameters, '+map', 'MAP', true);
        $configured = array_replace(self::defaults($instance), JsonMap::strings($instance->getSetupVars()));
        foreach (['GAME_TYPE' => '+game_type', 'GAME_MODE' => '+game_mode'] as $key => $argument) {
            if (in_array(self::game($template), ['cs2', 'csgo_legacy'], true)) {
                $parameters = self::bind($parameters, $argument, $key, '' !== ($configured[$key] ?? ''));
            }
        }
        if (!self::source2($template)) {
            $parameters = self::bind($parameters, '-tickrate', 'TICKRATE', false);
        }

        $optional = self::optionalArguments($template);
        $optionalParameters = '';
        $workshopParameters = '';
        if (isset($optional['WORKSHOP_MAP'])) {
            $parameters = self::removeArgument($parameters, '+workshop_start_map');
        }
        foreach (['STEAM_GSLT' => '+sv_setsteamaccount', 'AUTHKEY' => '-authkey', 'WORKSHOP_COLLECTION' => '+host_workshop_collection', 'WORKSHOP_MAP' => '+host_workshop_map', 'GAMEMODE' => '+gamemode'] as $key => $argument) {
            // Preserve explicit operator literals for custom native setups; remove
            // obsolete catalog placeholders and empty optional arguments.
            $literal = self::argument($template->getStartParams(), $argument);
            if (!isset($optional[$key]) && null !== $literal && '' !== $literal) {
                continue;
            }
            $parameters = self::removeArgument($parameters, $argument);
            $value = $configured[$key] ?? '';
            $hasSecret = array_key_exists($key, $instance->getSetupSecrets());
            if (!isset($optional[$key]) || ('' === $value && !$hasSecret)) {
                continue;
            }
            if ('AUTHKEY' === $key && '' === ($configured['WORKSHOP_MAP'] ?? '') && '' === ($configured['WORKSHOP_COLLECTION'] ?? '')) {
                continue;
            }
            if ('WORKSHOP_MAP' === $key && !self::source2($template) && '' !== ($configured['WORKSHOP_COLLECTION'] ?? '')) {
                $argument = '+workshop_start_map';
            }
            if (in_array(self::game($template), ['cs2', 'csgo_legacy'], true) && in_array($key, ['WORKSHOP_MAP', 'WORKSHOP_COLLECTION'], true)) {
                $workshopParameters .= ' '.$argument.' {{'.$key.'}}';
            } else {
                $optionalParameters .= ' '.$argument.' {{'.$key.'}}';
            }
        }
        if ('' !== $optionalParameters) {
            if (preg_match('/(?:^|\s)\+map\s/', $parameters)) {
                $parameters = preg_replace_callback('/(^|\s)(\+map\s)/', static fn (array $match): string => $match[1].trim($optionalParameters).' '.$match[2], $parameters, 1) ?? $parameters;
            } else {
                $parameters .= $optionalParameters;
            }
        }
        if ('' !== $workshopParameters) {
            // CS2 initializes on its normal map before selecting Workshop
            // content; it does not implement CS:GO's workshop_start_map.
            // Legacy CS:GO uses its Workshop command as the starting map.
            if (!self::source2($template)) {
                $parameters = self::removeArgument($parameters, '+map');
            }
            $parameters .= $workshopParameters;
        }
        // An explicit administrator-selected bind address stays intact. Public
        // heartbeat addresses may be NAT addresses and must not be inferred here.
        if (!preg_match('/(?:^|\s)[+-]ip\s+\S+/', $parameters)) {
            $parameters .= ' +ip 0.0.0.0';
        }
        if (!preg_match('/(?:^|\s)\+exec\s+(?:"server[.]cfg"|server[.]cfg)(?=\s|$)/i', $parameters)) {
            $parameters .= ' +exec server.cfg';
        }

        return $parameters;
    }

    public static function initialConfig(Instance $instance): string
    {
        if (!self::supports($instance->getTemplate())) {
            throw new \InvalidArgumentException('Für dieses Template ist keine Source-Standardkonfiguration vorgesehen.');
        }
        $values = [];
        $environment = $instance->getTemplate()->getEnvVars();
        foreach (array_keys($environment) as $index) {
            $text = JsonMap::strings($environment[$index]);
            if (isset($text['key'], $text['value'])) {
                $values[$text['key']] = $text['value'];
            }
        }
        $setupVars = JsonMap::strings($instance->getSetupVars());
        $values = array_replace($values, $setupVars);
        $values['SERVER_NAME'] = $instance->getServerName() ?? $values['SERVER_NAME'] ?? $instance->getTemplate()->getDisplayName();
        $lines = ['// Source server configuration'];
        foreach (['hostname' => 'SERVER_NAME', 'sv_password' => 'SERVER_PASSWORD', 'rcon_password' => 'RCON_PASSWORD'] as $convar => $key) {
            $value = $values[$key] ?? '';
            if ('change-me' === strtolower($value) && 'SERVER_NAME' !== $key && !array_key_exists($key, $setupVars)) {
                $value = '';
            }
            if (preg_match('/[\x00\r\n]/', $value)) {
                throw new \InvalidArgumentException('Der Startwert enthält ungültige Zeichen: '.$key);
            }
            $lines[] = $convar.' "'.addcslashes($value, '\\"').'"';
        }
        $lines[] = 'sv_lan 0';

        return implode("\n", $lines)."\n";
    }

    public static function commandDirective(string $key): bool
    {
        return in_array(strtolower($key), ['exec', 'execifexists', 'map', 'changelevel', 'quit', 'exit', 'alias', 'bind', 'unbind', 'wait'], true);
    }
}
