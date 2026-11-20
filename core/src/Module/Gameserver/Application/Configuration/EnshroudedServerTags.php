<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Configuration;

use App\Infrastructure\Config\JsonArray;
use App\Module\Core\Domain\Entity\Instance;

/** @psalm-import-type FileDefinition from StructuredGameConfiguration */
final class EnshroudedServerTags
{
    // enshrouded_server_readme.txt, Steam depot 2278521, manifest 8886672487109033979.
    private const array GROUPS = [
        'Spielstil' => [
            'LookingForPlayers' => 'Mitspieler gesucht',
            'BaseBuilding' => 'Basisbau',
            'Exploration' => 'Erkundung',
            'Roleplay' => 'Rollenspiel',
        ],
        'Sprachen' => [
            'English' => 'Englisch',
            'German' => 'Deutsch',
            'French' => 'Französisch',
            'Italian' => 'Italienisch',
            'Japanese' => 'Japanisch',
            'Korean' => 'Koreanisch',
            'Polish' => 'Polnisch',
            'Portuguese' => 'Portugiesisch',
            'Russian' => 'Russisch',
            'Spanish' => 'Spanisch',
            'Thai' => 'Thailändisch',
            'Turkish' => 'Türkisch',
            'Ukrainian' => 'Ukrainisch',
            'Chinese' => 'Chinesisch',
            'Taiwanese' => 'Taiwanisch',
        ],
    ];

    public static function isTagField(string $key): bool
    {
        $key = strtolower($key);

        return 'tags' === $key || str_starts_with($key, 'tags.');
    }

    /** @param FileDefinition $file */
    public static function manages(Instance $instance, array $file): bool
    {
        if ('json' !== $file['format'] || !EnshroudedConfiguration::supports($instance, $file['path'] ?? $file['label'])) {
            return false;
        }
        foreach (array_keys($file['schema']) as $key) {
            if (self::isTagField($key)) {
                // Leave explicitly configured operator fields and their constraints in charge.
                return false;
            }
        }

        return true;
    }

    /**
     * @param FileDefinition $file
     *
     * @return array{groups:array<string,list<array{key:string,label:string,selected:bool}>>,unknown_count:int,message:string}|null
     */
    public static function describe(Instance $instance, array $file, string $content): ?array
    {
        if (!self::manages($instance, $file)) {
            return null;
        }
        try {
            $tags = self::read(self::document($content));
        } catch (\InvalidArgumentException $exception) {
            return ['groups' => [], 'unknown_count' => 0, 'message' => $exception->getMessage()];
        }

        $groups = [];
        foreach (self::GROUPS as $name => $labels) {
            $groups[$name] = [];
            foreach ($labels as $key => $label) {
                $groups[$name][] = ['key' => $key, 'label' => $label, 'selected' => in_array($key, $tags, true)];
            }
        }

        return ['groups' => $groups, 'unknown_count' => count(array_diff($tags, array_keys(self::options()))), 'message' => ''];
    }

    /** @param FileDefinition $file */
    public static function patch(Instance $instance, array $file, string $content, mixed $input): string
    {
        $changes = JsonArray::fromValue($input);
        if (null === $changes) {
            throw new \InvalidArgumentException('Ungültige Auswahl der Server-Tags.');
        }
        if ([] === $changes) {
            return $content;
        }
        if (!self::manages($instance, $file)) {
            throw new \InvalidArgumentException('Für diese Datei gibt es keine Auswahl für Server-Tags.');
        }
        $options = self::options();
        foreach (array_keys($changes) as $key) {
            if (!is_string($key) || !isset($options[$key]) || !is_bool($changes[$key])) {
                throw new \InvalidArgumentException('Server-Tags müssen bekannte Kennungen und boolesche Auswahlwerte verwenden.');
            }
        }

        $data = self::document($content);
        $previous = self::read($data);
        $tags = array_values(array_filter($previous, static fn (string $tag): bool => false !== ($changes[$tag] ?? true)));
        foreach (array_keys($options) as $tag) {
            if (true === ($changes[$tag] ?? false) && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }
        if ($tags === $previous) {
            return $content;
        }
        $data->tags = $tags;

        return JsonGameConfigDocument::encode($data, $content);
    }

    /** @return array<string,string> */
    private static function options(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    private static function document(string $content): \stdClass
    {
        $data = JsonGameConfigDocument::decode($content);
        if (!$data instanceof \stdClass) {
            throw new \InvalidArgumentException('Server-Tags benötigen eine Konfigurationsdatei mit einem JSON-Objekt.');
        }

        return $data;
    }

    /** @return list<string> */
    private static function read(\stdClass $data): array
    {
        foreach (array_keys(get_object_vars($data)) as $key) {
            if ('tags' !== $key && 0 === strcasecmp((string) $key, 'tags')) {
                throw new \InvalidArgumentException('Die Schreibweise des Feldes tags bitte im Dateimanager prüfen.');
            }
        }
        $entries = property_exists($data, 'tags') ? JsonArray::fromValue($data->tags) : [];
        if (null === $entries || !array_is_list($entries)) {
            throw new \InvalidArgumentException('Die vorhandenen Server-Tags sind keine JSON-Liste. Bitte im Dateimanager prüfen.');
        }
        $tags = [];
        foreach (array_keys($entries) as $index) {
            if (!is_string($entries[$index])) {
                throw new \InvalidArgumentException('Die vorhandene Liste enthält Einträge, die kein Text sind. Bitte im Dateimanager prüfen.');
            }
            $tags[] = $entries[$index];
        }

        return $tags;
    }
}
