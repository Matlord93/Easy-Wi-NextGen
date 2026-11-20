<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;

/** Official Valheim CLI settings; custom scripts retain their own arguments. */
final class ValheimServerSetup
{
    private const ARGUMENTS = [
        'VALHEIM_PUBLIC' => '-public',
        'VALHEIM_CROSSPLAY' => '-crossplay',
        'VALHEIM_SAVE_INTERVAL' => '-saveinterval',
        'VALHEIM_BACKUP_COUNT' => '-backups',
        'VALHEIM_BACKUP_SHORT' => '-backupshort',
        'VALHEIM_BACKUP_LONG' => '-backuplong',
        'VALHEIM_PRESET' => '-preset',
        'VALHEIM_COMBAT' => '-modifier combat',
        'VALHEIM_DEATH_PENALTY' => '-modifier deathpenalty',
        'VALHEIM_RESOURCES' => '-modifier resources',
        'VALHEIM_RAIDS' => '-modifier raids',
        'VALHEIM_PORTALS' => '-modifier portals',
    ];

    public static function supports(Template $template): bool
    {
        if (!in_array(strtolower($template->getGameKey()), ['valheim', 'valheim_windows'], true)) {
            return false;
        }
        $parameters = $template->getStartParams();
        if (preg_match('/[\r\n;&|`]|\$\(/', $parameters) || !preg_match('/^\s*(?:"([^"]+)"|(\S+))/', $parameters, $match)) {
            return false;
        }
        $binary = strtolower(basename(str_replace('\\', '/', '' !== ($match[1] ?? '') ? $match[1] : ($match[2] ?? ''))));

        return in_array($binary, ['valheim_server.x86_64', 'valheim_server.exe'], true);
    }

    /** @return array<string,array{label:string,type:string,required:bool,scope:string,validation:array<string,mixed>,helptext:string}> */
    public static function fields(Template $template): array
    {
        if (!self::supports($template)) {
            return [];
        }
        $fields = [];
        foreach (array_keys(self::ARGUMENTS) as $key) {
            $meta = GameSetupProfiles::startup($template->getGameKey(), $key);
            $text = JsonMap::strings($meta);
            $fields[$key] = ['label' => $text['label'] ?? $key, 'type' => $text['type'] ?? 'text', 'required' => false, 'scope' => 'customer_allowed', 'validation' => JsonMap::fromValue($meta['validation'] ?? null) ?? [], 'helptext' => $text['helptext'] ?? ''];
        }

        return $fields;
    }

    /** @return non-empty-string */
    private static function pattern(string $argument, bool $value = true): string
    {
        $quoted = <<<'REGEX'
(?:"(?:\\.|[^"\\])*"|'[^']*')(*SKIP)(*F)|
REGEX;
        $suffix = $value ? '\s+(?:"([^"\r\n]*)"|\'([^\'\r\n]*)\'|([^\s]+))' : '';

        return '/'.$quoted.'(?:^|\s)'.str_replace(' ', '\s+', preg_quote($argument, '/')).$suffix.'(?=\s|$)/i';
    }

    /** @return array<string,string> */
    public static function defaults(Instance $instance): array
    {
        if (!self::supports($instance->getTemplate())) {
            return [];
        }
        $parameters = $instance->getTemplate()->getStartParams();
        $defaults = [];
        foreach (self::ARGUMENTS as $key => $argument) {
            $defaults[$key] = '';
            if ('VALHEIM_CROSSPLAY' === $key) {
                $defaults[$key] = 1 === preg_match(self::pattern('-crossplay', false), $parameters) ? '1' : '0';
            } else {
                $count = preg_match_all(self::pattern($argument), $parameters, $matches, PREG_SET_ORDER);
                if (is_int($count) && $count > 0) {
                    $match = $matches[$count - 1];
                    $value = '' !== ($match[1] ?? '') ? $match[1] : ('' !== ($match[2] ?? '') ? $match[2] : ($match[3] ?? ''));
                    if (!str_contains($value, '{{')) {
                        $defaults[$key] = strtolower($value);
                    }
                }
            }
        }
        $environment = $instance->getTemplate()->getEnvVars();
        foreach (array_keys($environment) as $index) {
            $text = JsonMap::strings($environment[$index]);
            if (isset($text['key'], $text['value']) && array_key_exists($text['key'], $defaults) && '' === $defaults[$text['key']]) {
                $defaults[$text['key']] = $text['value'];
            }
        }

        return $defaults;
    }

    public static function parameters(Instance $instance, string $parameters): string
    {
        if (!self::supports($instance->getTemplate())) {
            return $parameters;
        }
        $changes = array_intersect_key(JsonMap::strings($instance->getSetupVars()), self::ARGUMENTS);
        if ([] === $changes) {
            return $parameters;
        }
        $definitions = self::fields($instance->getTemplate());
        foreach ($changes as $key => $value) {
            if ('' === $value) {
                continue;
            }
            $validation = $definitions[$key]['validation'];
            $pattern = $validation['pattern'] ?? null;
            if (!is_string($pattern) || '' === $pattern || 1 !== preg_match($pattern, $value) || ('number' === $definitions[$key]['type'] && ((float) $value < (float) ($validation['min'] ?? 0) || (float) $value > (float) ($validation['max'] ?? PHP_INT_MAX)))) {
                throw new \InvalidArgumentException('Ungültige Valheim-Startvorgabe: '.$key);
            }
        }
        // Reorder world rules only when explicitly edited. A preset resets
        // modifiers, so individual rules must follow it in the launch command.
        $worldKeys = array_slice(self::ARGUMENTS, 6, null, true);
        $setKeys = '';
        if ([] !== array_intersect_key($changes, $worldKeys)) {
            $changes = array_replace(array_intersect_key(self::defaults($instance), $worldKeys), $changes);
            // Existing checkbox overrides must also follow the preset, which
            // would otherwise reset them. Keep their exact operator spelling.
            $parameters = preg_replace_callback(self::pattern('-setkey'), static function (array $match) use (&$setKeys): string {
                $setKeys .= ' '.trim($match[0]);

                return '';
            }, $parameters) ?? $parameters;
        }
        foreach (self::ARGUMENTS as $key => $argument) {
            if (!array_key_exists($key, $changes)) {
                continue;
            }
            $value = $changes[$key];
            if ('VALHEIM_CROSSPLAY' === $key) {
                $parameters = preg_replace(self::pattern('-crossplay', false), '', $parameters) ?? $parameters;
                if ('1' === $value) {
                    $parameters .= ' -crossplay';
                }
            } else {
                $parameters = preg_replace(self::pattern($argument), '', $parameters) ?? $parameters;
                if ('' !== $value) {
                    $parameters .= ' '.$argument.' {{'.$key.'}}';
                }
            }
        }

        return $parameters.$setKeys;
    }
}
