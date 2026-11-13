<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

/** Only observed guest fields may leave the provider boundary. */
final class VmGuestAgentReport
{
    public const STATES = ['reachable', 'not_running', 'disabled', 'unreachable', 'forbidden', 'unavailable', 'invalid_response', 'busy'];

    public static function observed(string $state, array $fields = []): array
    {
        return self::sanitize($fields + ['state' => $state, 'observed_at' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
    }

    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $state = in_array($input['state'] ?? null, self::STATES, true) ? $input['state'] : 'unavailable';
        $time = null;
        if (is_string($input['observed_at'] ?? null) && strlen($input['observed_at']) <= 40) {
            $canonical = str_ends_with($input['observed_at'], 'Z') ? substr($input['observed_at'], 0, -1).'+00:00' : $input['observed_at'];
            $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $canonical);
            if ($date && $date->format(DATE_ATOM) === $canonical) {
                $time = $date->format(DATE_ATOM);
            }
        }
        $result = ['state' => $state, 'observed_at' => $time];
        foreach (['version' => 128, 'hostname' => 255, 'os' => 255] as $key => $limit) {
            $result[$key] = 'reachable' === $state ? self::text($input[$key] ?? null, $limit) : null;
        }

        $result['network'] = 'reachable' === $state ? VmGuestNetworkReport::sanitize($input['network'] ?? null) : VmGuestNetworkReport::empty('unavailable');

        return $result;
    }

    public static function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && '' !== $value && strlen($value) <= $limit && mb_check_encoding($value, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : null;
    }
}
