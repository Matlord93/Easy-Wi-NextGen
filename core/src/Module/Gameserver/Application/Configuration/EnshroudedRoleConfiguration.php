<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\Instance;

/** Applies explicit role additions/removals after scalar edits, before the shared CAS write. */
final class EnshroudedRoleConfiguration
{
    private const int MAX_ROLES = 128;
    private const array PERMISSIONS = ['canKickBan', 'canAccessInventories', 'canEditWorld', 'canEditBase', 'canExtendBase'];

    private static function document(string $content): \stdClass
    {
        $data = JsonGameConfigDocument::decode($content);
        if (!$data instanceof \stdClass) {
            throw new \InvalidArgumentException('Die Enshrouded-Konfiguration muss ein JSON-Objekt sein.');
        }

        return $data;
    }

    /** @return list<\stdClass> */
    private static function roles(\stdClass $data): array
    {
        foreach (array_keys(get_object_vars($data)) as $key) {
            if (0 === strcasecmp((string) $key, 'userGroups') && 'userGroups' !== $key) {
                throw new \InvalidArgumentException('Abweichende Schreibweisen von userGroups bitte im Dateimanager prüfen.');
            }
        }
        $roles = property_exists($data, 'userGroups') ? JsonArray::fromValue($data->userGroups) : [];
        if (null === $roles || !array_is_list($roles) || count($roles) > self::MAX_ROLES) {
            throw new \InvalidArgumentException('Diese Rollenliste hat ein abweichendes Format oder mehr als 128 Einträge. Bitte im Dateimanager bearbeiten.');
        }
        $result = [];
        foreach (array_keys($roles) as $index) {
            $role = self::roleObject($roles[$index]);
            if (!is_string($role->name ?? null) || (property_exists($role, 'password') && !is_string($role->password))) {
                throw new \InvalidArgumentException('Rollenname und Rollenpasswort müssen Text sein. Bitte die Liste im Dateimanager prüfen.');
            }
            $result[] = $role;
        }
        if ([] === $result && property_exists($data, 'password') && '' !== $data->password) {
            throw new \InvalidArgumentException('Hier ist noch ein altes Serverpasswort hinterlegt. Enshrouded muss die bisherigen Zugänge beim regulären Start zuerst in Rollen umwandeln. Danach bitte neu analysieren; bestehende Zugänge werden nicht automatisch ersetzt.');
        }

        return $result;
    }

    private static function roleObject(mixed $value): \stdClass
    {
        if (!$value instanceof \stdClass) {
            throw new \InvalidArgumentException('Ein Rolleneintrag ist kein JSON-Objekt. Bitte im Dateimanager prüfen.');
        }

        return $value;
    }

    /**
     * @return array{editable:bool,message:string,max_roles:int,max_slots:int,slot_count:int|null,entries:list<array{index:int,name:string,public_access:bool}>}|null
     */
    public static function describe(Instance $instance, string $path, string $content): ?array
    {
        if (!EnshroudedConfiguration::supports($instance, $path)) {
            return null;
        }
        $message = '';
        $entries = [];
        try {
            $roles = self::roles(self::document($content));
            foreach ($roles as $index => $role) {
                $name = JsonMap::strings(get_object_vars($role))['name'] ?? '';
                $entries[] = ['index' => $index, 'name' => $name, 'public_access' => '' === ($role->password ?? '')];
            }
        } catch (\InvalidArgumentException $exception) {
            $message = $exception->getMessage();
        }
        $slots = EnshroudedConfiguration::explicitSlots($instance, GameConfigDocument::values($content, 'json'));

        return ['editable' => '' === $message, 'message' => $message, 'max_roles' => self::MAX_ROLES, 'max_slots' => min(16, $instance->getMaxSlots()), 'slot_count' => $slots, 'entries' => $entries];
    }

    private static function text(mixed $value, bool $name): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00\r\n]/', $value) || strlen($value) > 8192) {
            throw new \InvalidArgumentException('Rollenname und Passwort benötigen gültigen einzeiligen Text.');
        }
        if ($name) {
            $value = trim($value);
            if ('' === $value || mb_strlen($value) > 64) {
                throw new \InvalidArgumentException('Bitte einen Rollennamen mit 1 bis 64 Zeichen angeben.');
            }
        }

        return $value;
    }

    private static function flag(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('Rollenrechte müssen echte Schalterwerte sein.');
        }

        return $value;
    }

    private static function reserved(mixed $value, int $slots): int
    {
        if (!is_int($value) || $value < 0 || $value > $slots) {
            throw new \InvalidArgumentException('Reservierte Rollenplätze müssen zwischen 0 und dem aktuellen Spielerlimit liegen.');
        }

        return $value;
    }

    private static function create(mixed $value, int $slots): \stdClass
    {
        $data = JsonArray::fromValue($value);
        if (null === $data || array_diff(array_keys($data), ['name', 'password', 'reservedSlots', 'allowPublicAccess', ...self::PERMISSIONS])) {
            throw new \InvalidArgumentException('Ungültige neue Rolle oder unbekannte Rollenfelder.');
        }
        $role = new \stdClass();
        $role->name = self::text($data['name'] ?? null, true);
        $role->password = self::text($data['password'] ?? null, false);
        $public = self::flag(array_key_exists('allowPublicAccess', $data) ? $data['allowPublicAccess'] : false);
        if ('' === $role->password && !$public) {
            throw new \InvalidArgumentException('Bitte ein Rollenpasswort setzen oder den öffentlichen Zugang ohne Passwort bewusst freigeben.');
        }
        foreach (self::PERMISSIONS as $permission) {
            $role->{$permission} = self::flag(array_key_exists($permission, $data) ? $data[$permission] : false);
        }
        $role->reservedSlots = self::reserved(array_key_exists('reservedSlots', $data) ? $data['reservedSlots'] : 0, $slots);

        return $role;
    }

    /** @return array{add:array<array-key,mixed>,remove:array<array-key,mixed>} */
    private static function operations(mixed $value): array
    {
        $data = JsonArray::fromValue($value);
        if (null === $data || array_diff(array_keys($data), ['add', 'remove'])) {
            throw new \InvalidArgumentException('Ungültige Rollenoperationen.');
        }
        $add = JsonArray::fromValue(array_key_exists('add', $data) ? $data['add'] : []);
        $remove = JsonArray::fromValue(array_key_exists('remove', $data) ? $data['remove'] : []);
        if (null === $add || null === $remove || !array_is_list($add) || !array_is_list($remove) || count($add) > 32 || count($remove) > self::MAX_ROLES) {
            throw new \InvalidArgumentException('Bitte höchstens 32 neue Rollen und eindeutige Entfernungen als Listen übergeben.');
        }

        return ['add' => $add, 'remove' => $remove];
    }

    public static function patch(Instance $instance, string $path, string $previous, string $content, mixed $changes): string
    {
        $operations = self::operations($changes);
        if ([] === $operations['add'] && [] === $operations['remove']) {
            return $content;
        }
        if (!EnshroudedConfiguration::supports($instance, $path)) {
            throw new \InvalidArgumentException('Rollenverwaltung ist für diese Datei nicht vorgesehen.');
        }
        $original = self::roles(self::document($previous));
        $data = self::document($content);
        $roles = self::roles($data);
        if (count($original) !== count($roles)) {
            throw new \InvalidArgumentException('Die Rollenstruktur hat sich geändert. Bitte neu analysieren.');
        }
        $slots = EnshroudedConfiguration::explicitSlots($instance, GameConfigDocument::values($content, 'json'));
        if (null === $slots) {
            throw new \InvalidArgumentException('Bitte das Spielerlimit ausdrücklich innerhalb der gebuchten Plätze und höchstens 16 festlegen.');
        }
        $removed = [];
        foreach (array_keys($operations['remove']) as $position) {
            $index = self::index($operations['remove'][$position], count($original));
            if (isset($removed[$index])) {
                throw new \InvalidArgumentException('Eine Rolle darf nicht doppelt entfernt werden.');
            }
            $removed[$index] = true;
            unset($roles[$index]);
        }
        foreach (array_keys($operations['add']) as $position) {
            $role = self::create($operations['add'][$position], $slots);
            $name = JsonMap::strings(get_object_vars($role))['name'] ?? '';
            $password = JsonMap::strings(get_object_vars($role))['password'] ?? '';
            foreach ($roles as $existing) {
                $text = JsonMap::strings(get_object_vars($existing));
                if (mb_strtolower(trim($text['name'] ?? '')) === mb_strtolower($name)) {
                    throw new \InvalidArgumentException('Dieser Rollenname ist bereits vorhanden. Bitte einen eindeutigen Namen wählen.');
                }
                if (($text['password'] ?? '') === $password) {
                    throw new \InvalidArgumentException('Eine andere Rolle verwendet bereits dieses Passwort, gegebenenfalls einen leeren Zugang. Bitte ein anderes Passwort wählen.');
                }
            }
            $roles[] = $role;
        }
        if ([] === $roles || count($roles) > self::MAX_ROLES) {
            throw new \InvalidArgumentException('Bitte mindestens eine und höchstens 128 Rollen behalten. Eine letzte Rolle kann im selben Speichervorgang durch eine neue ersetzt werden.');
        }
        $data->userGroups = array_values($roles);

        return JsonGameConfigDocument::encode($data, $content);
    }

    private static function index(mixed $value, int $count): int
    {
        if (!is_int($value) || $value < 0 || $value >= $count) {
            throw new \InvalidArgumentException('Die zu entfernende Rolle gehört nicht zur eingelesenen Rollenliste.');
        }

        return $value;
    }
}
