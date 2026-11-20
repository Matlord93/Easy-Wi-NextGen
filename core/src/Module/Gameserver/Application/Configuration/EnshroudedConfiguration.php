<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Module\Core\Domain\Entity\Instance;

final class EnshroudedConfiguration
{
    public static function supports(Instance $instance, string $path): bool
    {
        return in_array(strtolower($instance->getTemplate()->getGameKey()), ['enshrouded', 'enshrouded_windows'], true)
            && 'enshrouded_server.json' === strtolower(basename(str_replace('\\', '/', $path)));
    }

    /** @param array<string,string> $values */
    public static function roleCategory(Instance $instance, string $path, string $key, array $values): ?string
    {
        if (!self::supports($instance, $path) || !preg_match('/^userGroups[.]([0-9]+)[.]/i', $key, $match)) {
            return null;
        }
        $name = trim($values['userGroups.'.$match[1].'.name'] ?? '');

        return 'Rolle: '.('' !== $name ? mb_substr($name, 0, 64) : '#'.((int) $match[1] + 1));
    }

    /** @param array<string,string> $values */
    public static function roleLimit(Instance $instance, string $path, string $key, array $values): ?int
    {
        if (!self::supports($instance, $path) || !preg_match('/^userGroups[.][0-9]+[.]reservedSlots$/i', $key)) {
            return null;
        }
        $slots = filter_var($values['slotCount'] ?? null, FILTER_VALIDATE_INT);

        return min(16, $instance->getMaxSlots(), is_int($slots) && $slots > 0 ? $slots : 16);
    }

    /** @param array<string,string> $values */
    public static function explicitSlots(Instance $instance, array $values): ?int
    {
        $slots = filter_var($values['slotCount'] ?? null, FILTER_VALIDATE_INT);

        return is_int($slots) && $slots >= 1 && $slots <= min(16, $instance->getMaxSlots()) ? $slots : null;
    }

    public static function assertSetupChange(Instance $instance, string $path, string $previous, string $content): void
    {
        if (!self::supports($instance, $path)) {
            return;
        }
        $before = GameConfigDocument::values($previous, 'json');
        $after = GameConfigDocument::values($content, 'json');
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $checkReservations = ($before['slotCount'] ?? null) !== ($after['slotCount'] ?? null);
        foreach ($keys as $key) {
            if (($before[$key] ?? null) === ($after[$key] ?? null)) {
                continue;
            }
            if (str_starts_with($key, 'gameSettings.') && 'Custom' !== ($after['gameSettingsPreset'] ?? null)) {
                throw new \InvalidArgumentException('Individuelle Enshrouded-Spielregeln benötigen die Vorlage Custom. Bitte diese ausdrücklich im selben Speichervorgang wählen; die Vorlage wird nicht automatisch geändert.');
            }
            $checkReservations = $checkReservations || 1 === preg_match('/^userGroups[.][0-9]+[.]reservedSlots$/i', $key);
        }
        if (!$checkReservations) {
            return;
        }
        $slots = self::explicitSlots($instance, $after);
        if (null === $slots) {
            throw new \InvalidArgumentException('Bitte das Enshrouded-Spielerlimit ausdrücklich innerhalb der gebuchten Plätze und höchstens 16 festlegen.');
        }
        foreach ($after as $key => $value) {
            if (!preg_match('/^userGroups[.][0-9]+[.]reservedSlots$/i', $key)) {
                continue;
            }
            $reserved = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($reserved) || $reserved < 0 || $reserved > $slots) {
                throw new \InvalidArgumentException('Reservierte Rollenplätze müssen zwischen 0 und dem aktuellen Spielerlimit liegen. Bitte die Reservierungen bei einer Verringerung des Spielerlimits mit anpassen.');
            }
        }
    }
}
