<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\Instance;

/** Changes individual list entries, retaining unknown IDs and object properties. */
final class VRisingProgressionConfiguration
{
    private const array LABELS = ['VBloodUnitSettings' => 'V-Blut-Bosse', 'UnlockedAchievements' => 'Fortschritt für neue Charaktere', 'UnlockedResearchs' => 'Forschung für neue Charaktere'];

    /** @var array<string,list<array{id:int,label:string}>>|null */
    private static ?array $catalog = null;

    public static function ownsField(Instance $instance, string $path, string $key): bool
    {
        return VRisingGameConfiguration::canInitialize($instance, $path) && in_array(strtolower(explode('.', $key)[0]), array_map(strtolower(...), array_keys(self::LABELS)), true);
    }

    /** @return array<string,list<array{id:int,label:string}>> */
    private static function catalog(): array
    {
        if (null !== self::$catalog) {
            return self::$catalog;
        }
        $content = file_get_contents(dirname(__DIR__, 5).'/config/gameserver/vrising_progression.json');
        if (false === $content) {
            throw new \RuntimeException('Der Fortschrittskatalog konnte nicht gelesen werden.');
        }
        $data = JsonMap::decode($content) ?? [];
        $result = [];
        foreach (array_keys(self::LABELS) as $key) {
            $result[$key] = [];
            $rows = $data[$key] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new \RuntimeException('Ungültiger Fortschrittskatalog.');
            }
            foreach (array_keys($rows) as $index) {
                $map = JsonMap::fromValue($rows[$index]) ?? [];
                $id = self::id($map['id'] ?? null);
                $label = JsonMap::strings($map)['label'] ?? null;
                if (null === $label) {
                    throw new \RuntimeException('Ungültige Katalogbeschriftung.');
                }
                $result[$key][] = ['id' => $id, 'label' => $label];
            }
        }

        return self::$catalog = $result;
    }

    private static function document(string $content): \stdClass
    {
        $data = JsonGameConfigDocument::decode($content, true);
        if (!$data instanceof \stdClass) {
            throw new \InvalidArgumentException('Die V-Rising-Konfiguration muss ein JSON-Objekt enthalten.');
        }

        return $data;
    }

    private static function property(\stdClass $data, string $key): string
    {
        $matches = [];
        foreach (array_keys(get_object_vars($data)) as $name) {
            $name = (string) $name;
            if (0 === strcasecmp($name, $key)) {
                $matches[] = $name;
            }
        }
        if (count($matches) > 1) {
            throw new \InvalidArgumentException('Mehrdeutige Namen in der Datei. Bitte im Dateimanager prüfen.');
        }

        return $matches[0] ?? $key;
    }

    private static function value(\stdClass $data, string $key, mixed $default): mixed
    {
        $name = self::property($data, $key);

        return property_exists($data, $name) ? $data->{$name} : $default;
    }

    private static function id(mixed $value): int
    {
        if (!is_int($value) || $value < -2147483648 || $value > 2147483647) {
            throw new \InvalidArgumentException('Kennungen müssen native 32-Bit-Ganzzahlen sein.');
        }

        return $value;
    }

    /** @return array<int,int|\stdClass> */
    private static function entries(\stdClass $data, string $key): array
    {
        $name = self::property($data, $key);
        $list = property_exists($data, $name) ? $data->{$name} : [];
        if (!is_array($list) || !array_is_list($list) || count($list) > 512) {
            throw new \InvalidArgumentException('Diese Liste hat ein abweichendes Format oder mehr als 512 Einträge. Bitte im Dateimanager bearbeiten.');
        }
        $entries = [];
        foreach ($list as $item) {
            if ('VBloodUnitSettings' === $key) {
                if (!$item instanceof \stdClass) {
                    throw new \InvalidArgumentException('V-Blut-Einträge müssen JSON-Objekte sein.');
                }
                $id = self::id(self::value($item, 'UnitId', null));
                self::bossValues(self::value($item, 'UnitLevel', 0), self::value($item, 'DefaultUnlocked', false));
            } else {
                $id = self::id($item);
                $item = $id;
            }
            if (array_key_exists($id, $entries)) {
                throw new \InvalidArgumentException('Doppelte Kennungen sind nicht eindeutig bearbeitbar. Bitte im Dateimanager prüfen.');
            }
            $entries[$id] = $item;
        }

        return $entries;
    }

    /** @return array{UnitLevel:int,DefaultUnlocked:bool} */
    private static function bossValues(mixed $level, mixed $unlocked): array
    {
        if (!is_int($level) || $level < 0 || $level > 255 || !is_bool($unlocked)) {
            throw new \InvalidArgumentException('Die Boss-Stufe muss eine ganze Zahl von 0 bis 255 und die Freischaltung ein Schalterwert sein.');
        }

        return ['UnitLevel' => $level, 'DefaultUnlocked' => $unlocked];
    }

    /**
     * @return list<array{key:string,label:string,kind:string,editable:bool,message:string,items:list<array{id:int,label:string,selected:bool,level:int,unlocked:bool}>}>
     */
    public static function describe(Instance $instance, string $path, string $content): array
    {
        if (!VRisingGameConfiguration::canInitialize($instance, $path)) {
            return [];
        }
        $data = self::document($content);
        $groups = [];
        foreach (self::LABELS as $key => $label) {
            $message = '';
            try {
                $entries = self::entries($data, $key);
            } catch (\InvalidArgumentException $exception) {
                $entries = [];
                $message = $exception->getMessage();
            }
            $choices = array_column(self::catalog()[$key], 'label', 'id');
            foreach (array_keys($entries) as $id) {
                $choices[$id] ??= 'Unbekannte Kennung ('.$id.')';
            }
            $items = [];
            foreach ($choices as $id => $name) {
                $entry = $entries[$id] ?? null;
                $boss = $entry instanceof \stdClass ? self::bossValues(self::value($entry, 'UnitLevel', 0), self::value($entry, 'DefaultUnlocked', false)) : ['UnitLevel' => 0, 'DefaultUnlocked' => false];
                $items[] = ['id' => $id, 'label' => $name, 'selected' => null !== $entry, 'level' => $boss['UnitLevel'], 'unlocked' => $boss['DefaultUnlocked']];
            }
            $groups[] = ['key' => $key, 'label' => $label, 'kind' => 'VBloodUnitSettings' === $key ? 'bosses' : 'ids', 'editable' => '' === $message, 'message' => $message, 'items' => $items];
        }

        return $groups;
    }

    /** @return list<int> */
    private static function ids(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 256) {
            throw new \InvalidArgumentException('Bitte höchstens 256 eindeutige Kennungen pro Änderung übergeben.');
        }
        $ids = array_map(self::id(...), $values);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Kennungen dürfen in einer Änderung nicht doppelt vorkommen.');
        }

        return $ids;
    }

    public static function patch(Instance $instance, string $path, string $content, mixed $changes): string
    {
        if ([] === $changes) {
            return $content;
        }
        if (!is_array($changes) || array_is_list($changes) || !VRisingGameConfiguration::canInitialize($instance, $path)) {
            throw new \InvalidArgumentException('Diese Listen gehören nicht zur gewählten Konfiguration.');
        }
        $data = self::document($content);
        foreach (array_keys($changes) as $key) {
            if (!is_string($key) || !isset(self::LABELS[$key])) {
                throw new \InvalidArgumentException('Ungültige Fortschrittsliste.');
            }
            $bosses = 'VBloodUnitSettings' === $key;
            $operations = self::operations($changes[$key], $bosses);
            $entries = self::entries($data, $key);
            $known = array_column(self::catalog()[$key], null, 'id');
            $remove = self::ids($operations['remove'] ?? []);
            $upserts = $bosses ? self::bossChanges($operations['upsert'] ?? []) : array_fill_keys(self::ids($operations['add'] ?? []), null);
            if ([] === $remove && [] === $upserts) {
                continue;
            }
            if (array_intersect($remove, array_keys($upserts))) {
                throw new \InvalidArgumentException('Eine Kennung kann nicht zugleich ergänzt und entfernt werden.');
            }
            foreach ($upserts as $id => $values) {
                if (!isset($known[$id]) && !array_key_exists($id, $entries)) {
                    throw new \InvalidArgumentException('Neue Kennungen müssen im dokumentierten Katalog enthalten sein. Vorhandene Mod-Einträge bleiben bearbeitbar.');
                }
                if ($bosses) {
                    $entry = isset($entries[$id]) && $entries[$id] instanceof \stdClass ? clone $entries[$id] : new \stdClass();
                    $entry->{self::property($entry, 'UnitId')} = $id;
                    foreach ($values ?? [] as $name => $value) {
                        $entry->{self::property($entry, $name)} = $value;
                    }
                    $entries[$id] = $entry;
                } else {
                    $entries[$id] = $id;
                }
            }
            foreach ($remove as $id) {
                if (!array_key_exists($id, $entries)) {
                    throw new \InvalidArgumentException('Die zu entfernende Kennung ist nicht in der Datei vorhanden.');
                }
                unset($entries[$id]);
            }
            if (count($entries) > 512) {
                throw new \InvalidArgumentException('Die Liste darf höchstens 512 Einträge enthalten.');
            }
            $property = self::property($data, $key);
            $data->{$property} = array_values($entries);
        }

        return JsonGameConfigDocument::encode($data, $content);
    }

    /** @return array<string,array<array-key,mixed>> */
    private static function operations(mixed $value, bool $bosses): array
    {
        $operations = JsonArray::fromValue($value);
        if (null === $operations) {
            throw new \InvalidArgumentException('Ungültige Listenoperationen.');
        }
        if ([] === $operations) {
            return [];
        }
        if (array_is_list($operations)) {
            throw new \InvalidArgumentException('Listenoperationen benötigen benannte Felder.');
        }
        $result = [];
        foreach (array_keys($operations) as $name) {
            if (!is_string($name) || !in_array($name, [$bosses ? 'upsert' : 'add', 'remove'], true)) {
                throw new \InvalidArgumentException('Unbekannte Listenoperation.');
            }
            $entries = JsonArray::fromValue($operations[$name]);
            if (null === $entries) {
                throw new \InvalidArgumentException('Listenoperationen benötigen JSON-Listen.');
            }
            $result[$name] = $entries;
        }

        return $result;
    }

    /** @return array<int,array{UnitLevel:int,DefaultUnlocked:bool}> */
    private static function bossChanges(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 256) {
            throw new \InvalidArgumentException('Ungültige Liste von Boss-Änderungen.');
        }
        $result = [];
        foreach ($values as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['UnitId', 'UnitLevel', 'DefaultUnlocked'])) {
                throw new \InvalidArgumentException('Ungültige Boss-Änderung.');
            }
            $id = self::id($row['UnitId'] ?? null);
            if (isset($result[$id])) {
                throw new \InvalidArgumentException('Doppelte Boss-Änderung.');
            }
            $result[$id] = self::bossValues($row['UnitLevel'] ?? null, $row['DefaultUnlocked'] ?? null);
        }

        return $result;
    }
}
