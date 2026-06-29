<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Enum\MusicbotPermission;

final class TeamspeakCommandCatalog
{
    public const PLUGIN_IDENTIFIER = 'easywi.teamspeak.integration';

    /** @var list<string> */
    public const SUPPORTED_COMMANDS = ['play', 'pause', 'resume', 'stop', 'skip', 'next', 'volume', 'queue', 'clear', 'shuffle', 'repeat', 'radio', 'yt', 'youtube', 'song', 'np', 'help'];

    /** @return array<string, array<string, mixed>> */
    public static function build(array $overrides = []): array
    {
        $commands = [];
        foreach (self::defaults() as $name => $default) {
            $override = is_array($overrides[$name] ?? null) ? $overrides[$name] : [];
            $commands[$name] = array_replace($default, array_intersect_key($override, $default));
            $commands[$name]['aliases'] = self::stringList($override['aliases'] ?? $default['aliases']);
            $commands[$name]['roles'] = self::roles($override['roles'] ?? $default['roles']);
            $commands[$name]['chat_scopes'] = self::chatScopes($override['chat_scopes'] ?? $default['chat_scopes']);
            $commands[$name]['plugin'] = self::PLUGIN_IDENTIFIER;
        }

        return $commands;
    }

    /** @return array<string, array<string, mixed>> */
    private static function defaults(): array
    {
        $basic = ['user'];
        $dj = ['dj', 'admin'];
        $admin = ['admin'];
        $both = ['channel', 'private'];

        $playback  = MusicbotPermission::PlaybackControl->value;
        $queue     = MusicbotPermission::QueueManage->value;
        $webradio  = MusicbotPermission::WebradioManage->value;
        $view      = MusicbotPermission::View->value;

        return [
            'play'    => self::command('play',        ['yt', 'youtube', 'radio'], $dj,    $both, $playback),
            'pause'   => self::command('pause',       [],                         $dj,    $both, $playback),
            'resume'  => self::command('resume',      [],                         $dj,    $both, $playback),
            'stop'    => self::command('stop',        [],                         $dj,    $both, $playback),
            'skip'    => self::command('skip',        ['next'],                   $dj,    $both, $playback),
            'next'    => self::command('skip',        ['skip'],                   $dj,    $both, $playback),
            'volume'  => self::command('volume',      [],                         $dj,    $both, $playback),
            'queue'   => self::command('queue',       [],                         $basic, $both, $queue),
            'clear'   => self::command('clear',       [],                         $admin, $both, $queue),
            'shuffle' => self::command('shuffle',     [],                         $dj,    $both, $queue),
            'repeat'  => self::command('repeat',      [],                         $dj,    $both, $queue),
            'radio'   => self::command('radio',       [],                         $dj,    $both, $webradio),
            'yt'      => self::command('play',        ['youtube'],                $dj,    $both, $playback),
            'youtube' => self::command('play',        ['yt'],                     $dj,    $both, $playback),
            'song'    => self::command('now_playing', ['np'],                     $basic, $both, $view),
            'np'      => self::command('now_playing', ['song'],                   $basic, $both, $view),
            'help'    => self::command('help',        [],                         $basic, $both, $view),
        ];
    }

    /** @param list<string> $aliases @param list<string> $roles @param list<string> $chatScopes */
    private static function command(string $action, array $aliases, array $roles, array $chatScopes, string $permission): array
    {
        return ['enabled' => true, 'action' => $action, 'aliases' => $aliases, 'roles' => $roles, 'chat_scopes' => $chatScopes, 'permission' => $permission, 'plugin' => self::PLUGIN_IDENTIFIER];
    }

    /** @return list<string> */
    private static function roles(mixed $value): array { return self::filtered($value, ['user', 'dj', 'admin']); }
    /** @return list<string> */
    private static function chatScopes(mixed $value): array { return self::filtered($value, ['channel', 'private']); }
    /** @return list<string> */
    private static function stringList(mixed $value): array { return self::filtered($value, null); }

    /** @param list<string>|null $allowed @return list<string> */
    private static function filtered(mixed $value, ?array $allowed): array
    {
        if (!is_array($value)) { return []; }
        $result = [];
        foreach ($value as $item) {
            $item = strtolower(trim((string) $item));
            if ($item === '' || ($allowed !== null && !in_array($item, $allowed, true))) { continue; }
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }
}
