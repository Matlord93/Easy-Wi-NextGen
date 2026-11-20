<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;

/** Additional admin connections share the customer's booked server capacity. */
final class VRisingHostConfiguration
{
    private static function supports(Instance $instance, string $path): bool
    {
        return in_array(strtolower($instance->getTemplate()->getGameKey()), ['v_rising', 'v_rising_windows'], true)
            && 'serverhostsettings.json' === strtolower(basename(str_replace('\\', '/', $path)));
    }

    public static function isAdminReserve(Instance $instance, string $path, string $key): bool
    {
        return self::supports($instance, $path) && 'maxconnectedadmins' === strtolower($key);
    }

    public static function canInitialize(Instance $instance, string $path): bool
    {
        return self::supports($instance, $path);
    }

    public static function initialConfig(Instance $instance, string $path): string
    {
        if (!self::supports($instance, $path) || $instance->getMaxSlots() < 1) {
            throw new \InvalidArgumentException('Für diese Host-Datei ist keine gültige gebuchte Kapazität hinterlegt.');
        }

        $players = $instance->getCurrentSlots() > 0 ? $instance->getCurrentSlots() : $instance->getMaxSlots();

        return json_encode(['MaxConnectedUsers' => min(128, $instance->getMaxSlots(), $players), 'MaxConnectedAdmins' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    }

    public static function hasCapacityOverride(Template $template): bool
    {
        if (!in_array(strtolower($template->getGameKey()), ['v_rising', 'v_rising_windows'], true)) {
            return false;
        }
        $quoted = <<<'REGEX'
(?:"(?:\\.|[^"\\])*"|'[^']*')(*SKIP)(*F)|
REGEX;

        return 1 === preg_match('/'.$quoted.'(?:^|\s)-(?:maxUsers|maxAdmins|maxConnectedUsers|maxConnectedAdmins)\s+/i', $template->getStartParams());
    }

    public static function parameters(Instance $instance, string $parameters): string
    {
        if (!self::supports($instance, 'ServerHostSettings.json') || !preg_match('/^\s*(?:"([^"]+)"|(\S+))/', $parameters, $match)) {
            return $parameters;
        }
        $binary = strtolower(basename(str_replace('\\', '/', '' !== ($match[1] ?? '') ? $match[1] : ($match[2] ?? ''))));
        if (!in_array($binary, ['vrisingserver', 'vrisingserver.exe'], true) || preg_match('/[\r\n;&|`]|\$\(/', $parameters)) {
            return $parameters;
        }
        $ports = [];
        $required = $instance->getTemplate()->getRequiredPorts();
        foreach (array_keys($required) as $index) {
            $text = \App\Infrastructure\Config\JsonMap::strings($required[$index]);
            $ports[] = strtolower($text['name'] ?? '');
        }
        $quoted = <<<'REGEX'
(?:"(?:\\.|[^"\\])*"|'[^']*')(*SKIP)(*F)|
REGEX;
        foreach (['game' => '(?:gamePort|serverPort)', 'query' => 'queryPort'] as $role => $argument) {
            if (!in_array($role, $ports, true)) {
                continue;
            }
            $parameters = preg_replace('/'.$quoted.'(?:^|\s)-'.$argument.'\s+(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s]+)(?=\s|$)/i', '', $parameters) ?? $parameters;
            $parameters .= ' -'.('game' === $role ? 'gamePort' : 'queryPort').' {{PORT_'.strtoupper($role).'}}';
        }

        return $parameters;
    }

    public static function assertEditable(Instance $instance, string $path, string $previous, string $content, bool $allowSlotChange): void
    {
        if (!self::supports($instance, $path)) {
            return;
        }
        $before = array_change_key_case(GameConfigDocument::values($previous, 'jsonc'), CASE_LOWER);
        $after = array_change_key_case(GameConfigDocument::values($content, 'jsonc'), CASE_LOWER);
        if (($before['autosavesmartkeep'] ?? null) !== ($after['autosavesmartkeep'] ?? null) && isset($after['autosavesmartkeep']) && '' !== $after['autosavesmartkeep']) {
            if (1 !== preg_match('/^[0-9]+:[0-9]+:[0-9]+(?:,[0-9]+:[0-9]+:[0-9]+)*$/D', $after['autosavesmartkeep'])) {
                throw new \InvalidArgumentException('Die Sicherungsaufbewahrung benötigt Zeitfenster im Format A:B:C, getrennt durch Kommas.');
            }
            foreach (preg_split('/[:,]/', $after['autosavesmartkeep']) ?: [] as $part) {
                if (strlen($part) > 10 || (float) $part > 2147483647) {
                    throw new \InvalidArgumentException('Die Zahlen der Sicherungsaufbewahrung müssen im unterstützten Ganzzahlbereich liegen.');
                }
            }
        }
        $keys = ['maxconnectedusers', 'maxconnectedadmins'];
        $beforeSlots = array_intersect_key($before, array_flip($keys));
        $afterSlots = array_intersect_key($after, array_flip($keys));
        ksort($beforeSlots);
        ksort($afterSlots);
        if ($beforeSlots === $afterSlots) {
            return;
        }
        if (!$allowSlotChange || $instance->isLockSlots()) {
            throw new \InvalidArgumentException('Spielerlimit und Adminreserve können nur im freigegebenen Setup geändert werden.');
        }
        // No guessed game defaults: both limits must be explicit when changed.
        $users = filter_var($after['maxconnectedusers'] ?? null, FILTER_VALIDATE_INT);
        $admins = filter_var($after['maxconnectedadmins'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($users) || !is_int($admins) || $users < 1 || $users > 128 || $admins < 0 || $users > $instance->getMaxSlots() || $admins > $instance->getMaxSlots() - $users) {
            throw new \InvalidArgumentException('Bitte Spielerlimit und Adminreserve gemeinsam festlegen: zusammen höchstens '.$instance->getMaxSlots().' gebuchte Plätze, davon höchstens 128 normale Spielerplätze.');
        }
    }
}
