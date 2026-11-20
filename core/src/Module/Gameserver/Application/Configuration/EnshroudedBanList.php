<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\Instance;

/** Exposes moderation data from the last saved Enshrouded file. */
final class EnshroudedBanList
{
    // Enshrouded 1.0 writes bannedAccounts. Older example files use bans.
    private const array KEYS = ['bannedaccounts', 'bans'];

    public static function ownsField(string $key): bool
    {
        $root = explode('.', $key, 2)[0];

        return in_array(strtolower($root), self::KEYS, true);
    }

    /** @return list<array{key:string,list_valid:bool,entries:list<array{display_name:string,character_name:string,ban_date:int|null}>,unreadable:int}>|null */
    public static function describe(Instance $instance, string $path, string $content): ?array
    {
        if (!EnshroudedConfiguration::supports($instance, $path)) {
            return null;
        }

        $document = JsonGameConfigDocument::decode($content);
        if (!$document instanceof \stdClass) {
            return [];
        }
        $groups = [];
        foreach (array_keys(get_object_vars($document)) as $key) {
            if (!is_string($key) || !in_array(strtolower($key), self::KEYS, true)) {
                continue;
            }
            $list = JsonArray::fromValue($document->{$key});
            if (null === $list || !array_is_list($list)) {
                $groups[] = ['key' => $key, 'list_valid' => false, 'entries' => [], 'unreadable' => 0];
                continue;
            }
            $entries = [];
            $unreadable = 0;
            foreach (array_keys($list) as $index) {
                if (!$list[$index] instanceof \stdClass) {
                    ++$unreadable;
                    continue;
                }
                $entry = $list[$index];
                $names = JsonMap::strings(get_object_vars($entry));
                $entries[] = [
                    'display_name' => $names['displayName'] ?? '',
                    'character_name' => $names['characterName'] ?? '',
                    'ban_date' => self::timestamp($entry->banDate ?? null),
                ];
            }
            $groups[] = ['key' => $key, 'list_valid' => true, 'entries' => $entries, 'unreadable' => $unreadable];
        }

        return $groups;
    }

    private static function timestamp(mixed $value): ?int
    {
        return is_int($value) && $value > 0 && $value <= 253402300799 ? $value : null;
    }
}
