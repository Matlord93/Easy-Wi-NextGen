<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use App\Module\Gameserver\Application\GameserverMemoryBudget;
use PHPUnit\Framework\TestCase;

final class GameConfigDocumentTest extends TestCase
{
    public function testPropertiesKeepCommentsUnknownKeysAndContinuedValues(): void
    {
        $content = "# Minecraft\r\nmotd=Willkommen\\u00fc\r\nmax-players=20\r\nserver-port=25565\r\ncustom=first\\\r\n  second=value\r\nunknown=keep\\:me\r\n";
        $values = GameConfigDocument::values($content, 'properties');
        self::assertSame('Willkommenü', $values['motd']);
        self::assertArrayNotHasKey('second', $values);
        $saved = GameConfigDocument::patch($content, 'properties', ['motd' => 'Grüße 😀', 'max-players' => '12']);
        self::assertStringContainsString("custom=first\\\r\n  second=value\r\nunknown=keep\\:me", $saved);
        self::assertStringContainsString("# Minecraft\r\n", $saved);
        self::assertSame('Grüße 😀', GameConfigDocument::values($saved, 'properties')['motd']);
        self::assertSame('12', GameConfigDocument::values($saved, 'properties')['max-players']);
        self::assertSame('25565', GameConfigDocument::values($saved, 'properties')['server-port']);
        self::assertSame('25566', GameConfigDocument::values('server-\\u0070ort=25566', 'properties')['server-port']);
    }

    public function testPropertiesReadLogicalLinesForPolicyWithoutExposingContinuedFields(): void
    {
        $content = "# comment\\\nserver-port=25\\\n  565\nserver-\\\n ip=203.0.113.10\nmax-players=2\\\n\t0\nmotd=Hello\n";
        $visible = GameConfigDocument::values($content, 'properties');
        self::assertSame('Hello', $visible['motd']);
        self::assertArrayNotHasKey('server-port', $visible);
        $all = GameConfigDocument::values($content, 'properties', true);
        self::assertSame('25565', $all['server-port']);
        self::assertSame('203.0.113.10', $all['server-ip']);
        self::assertSame('20', $all['max-players']);
        self::assertSame('25565', GameConfigDocument::values("# ignored\\\nserver-port=25565", 'properties')['server-port']);
        self::assertSame('25565', GameConfigDocument::values('server-port=25565\\', 'properties', true)['server-port']);
    }

    public function testPropertiesRetainLeadingSpacesAndSupportEmptyBareKeys(): void
    {
        $content = "# title\\\nmotd\nserver-port=25565\n";
        self::assertSame('', GameConfigDocument::values($content, 'properties')['motd']);
        $saved = GameConfigDocument::patch($content, 'properties', ['motd' => '  Welcome  ']);
        self::assertSame('  Welcome  ', GameConfigDocument::values($saved, 'properties')['motd']);
        self::assertStringContainsString('server-port=25565', $saved);
        self::assertStringStartsWith("# title\\\n", $saved);
        self::assertSame('', GameConfigDocument::values('server-ip', 'properties')['server-ip']);
    }

    public function testConvarAndIniKeepUnchangedDirectivesAndInlineComments(): void
    {
        $cfg = "// game server\nhostname \"Old name\" // public title\nsv_password \"\"\nexec other.cfg\n";
        self::assertSame('', GameConfigDocument::values($cfg, 'convar')['sv_password']);
        $saved = GameConfigDocument::patch($cfg, 'convar', ['hostname' => 'New "name"']);
        self::assertStringContainsString(' // public title', $saved);
        self::assertStringContainsString('exec other.cfg', $saved);
        self::assertSame('New "name"', GameConfigDocument::values($saved, 'convar')['hostname']);
        $ini = "[Server]\nName=\"Old\" ; keep this\nMaxPlayers=20\n[Other]\nCustom=preserve\n";
        $saved = GameConfigDocument::patch($ini, 'ini', ['Server.Name' => 'New name']);
        self::assertStringContainsString('; keep this', $saved);
        self::assertSame('New name', GameConfigDocument::values($saved, 'ini')['Server.Name']);
        self::assertSame('20', GameConfigDocument::values($saved, 'ini')['Server.MaxPlayers']);
    }

    public function testStructuredFormatsKeepNestedValuesAndTypes(): void
    {
        foreach (['json' => '{"server":{"enabled":true,"players":20,"rate":0.5},"unknown":["keep",null]}', 'yaml' => "server:\n  enabled: true\n  players: 20\n  rate: 0.5\nunknown: [keep, null]\n"] as $format => $content) {
            $saved = GameConfigDocument::patch($content, $format, ['server.players' => '12', 'server.enabled' => 'false', 'server.rate' => '1.25']);
            $data = 'json' === $format ? json_decode($saved, true, flags: JSON_THROW_ON_ERROR) : \Symfony\Component\Yaml\Yaml::parse($saved);
            self::assertSame(12, $data['server']['players']);
            self::assertFalse($data['server']['enabled']);
            self::assertSame(1.25, $data['server']['rate']);
            self::assertSame(['keep', null], $data['unknown']);
        }
    }

    public function testJsonPatchingPreservesObjectsListsAndUntouchedValues(): void
    {
        $content = '{"name":"Old","settings":{},"numeric":{"0":"zero","1":"one"},"list":[{},[],{"enabled":true}],"rate":1.0,"nullable":null}';
        $saved = GameConfigDocument::patch($content, 'json', ['name' => 'New', 'numeric.1' => 'changed', 'list.2.enabled' => 'false']);
        $data = json_decode($saved, false, flags: JSON_THROW_ON_ERROR);
        self::assertSame('New', $data->name);
        self::assertInstanceOf(\stdClass::class, $data->settings);
        self::assertSame([], get_object_vars($data->settings));
        self::assertInstanceOf(\stdClass::class, $data->numeric);
        self::assertSame('zero', $data->numeric->{'0'});
        self::assertSame('changed', $data->numeric->{'1'});
        self::assertIsArray($data->list);
        self::assertInstanceOf(\stdClass::class, $data->list[0]);
        self::assertSame([], $data->list[1]);
        self::assertFalse($data->list[2]->enabled);
        self::assertSame(1.0, $data->rate);
        self::assertNull($data->nullable);
        self::assertSame([], json_decode(GameConfigDocument::patch('[]', 'json', []), false, flags: JSON_THROW_ON_ERROR));
        self::assertInstanceOf(\stdClass::class, json_decode(GameConfigDocument::patch('{}', 'json', []), false, flags: JSON_THROW_ON_ERROR));
    }

    public function testYamlPreservesListsMappingsAndUneditedAliases(): void
    {
        $content = "name: Old\nempty_list: []\nempty_map: {}\nnumeric_map: {0: zero, 1: one}\ndefaults: &base\n  rate: 1.0\nleft: *base\nright: *base\nreleased: 2026-11-19\nquoted_date: '2026-11-19'\n";
        $saved = GameConfigDocument::patch($content, 'yaml', ['name' => 'New', 'left.rate' => '1.5']);
        $data = \Symfony\Component\Yaml\Yaml::parse($saved, \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP | \Symfony\Component\Yaml\Yaml::PARSE_DATETIME);
        self::assertSame('New', $data->name);
        self::assertSame([], $data->empty_list);
        self::assertInstanceOf(\stdClass::class, $data->empty_map);
        self::assertInstanceOf(\stdClass::class, $data->numeric_map);
        self::assertSame('zero', $data->numeric_map->{'0'});
        self::assertSame('one', $data->numeric_map->{'1'});
        self::assertSame(1.5, $data->left->rate);
        self::assertSame(1.0, $data->defaults->rate);
        self::assertSame(1.0, $data->right->rate);
        self::assertInstanceOf(\DateTimeInterface::class, $data->released);
        self::assertSame('2026-11-19', $data->released->format('Y-m-d'));
        self::assertSame('2026-11-19', $data->quoted_date);
        self::assertSame([], \Symfony\Component\Yaml\Yaml::parse(GameConfigDocument::patch('[]', 'yaml', []), \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP));
        self::assertInstanceOf(\stdClass::class, \Symfony\Component\Yaml\Yaml::parse(GameConfigDocument::patch('{}', 'yaml', []), \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP));
    }

    public function testDecimalValuesRemainEditableWithoutTruncation(): void
    {
        foreach (['json' => '{"rate":1.0,"count":1}', 'yaml' => "rate: 1.0\ncount: 1\n"] as $format => $content) {
            self::assertSame('1.0', GameConfigDocument::values($content, $format)['rate']);
            $saved = GameConfigDocument::patch($content, $format, ['rate' => '1.25', 'count' => '1.5']);
            $data = 'json' === $format ? json_decode($saved, true, flags: JSON_THROW_ON_ERROR) : \Symfony\Component\Yaml\Yaml::parse($saved);
            self::assertSame(1.25, $data['rate']);
            self::assertSame(1.5, $data['count']);
        }
    }

    public function testScalarDocumentsKeepTheirRawEditorCompatibility(): void
    {
        foreach (['json' => ['"license"', 'true', 'null', '42'], 'yaml' => ['license', 'true', 'null', '42', '2026-11-19']] as $format => $documents) {
            foreach ($documents as $content) {
                self::assertSame([], GameConfigDocument::values($content, $format));
            }
        }
    }

    public function testPurchasedHeapGetsSeparateNativeReserveOnlyForJava(): void
    {
        self::assertSame(5120, GameserverMemoryBudget::runtimeMiB(4096, 'minecraft_vanilla'));
        self::assertSame(768, GameserverMemoryBudget::runtimeMiB(512, 'papermc_paper'));
        self::assertSame(4096, GameserverMemoryBudget::runtimeMiB(4096, 'minecraft_bedrock'));
        self::assertSame(4096, GameserverMemoryBudget::runtimeMiB(4096, 'steamcmd'));
    }
}
