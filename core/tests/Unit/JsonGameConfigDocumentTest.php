<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use App\Module\Gameserver\Application\Configuration\GameConfigurationAdditions;
use App\Module\Gameserver\Application\Configuration\JsonGameConfigDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonGameConfigDocumentTest extends TestCase
{
    public function testNativeBomCommentsTrailingCommasAndStringEscapesKeepTheirData(): void
    {
        $content = "\xEF\xBB\xBF".<<<'JSON'
{
    // Unity-style configuration
    "Name": "https://example.test/a/*b*/,}",
    "Escaped": "a\" // text \\",
    "Enabled": true, /* operator comment */
    "Custom": [{"keep": 1,}, null, [],],
}
JSON;
        $values = GameConfigDocument::values($content, 'jsonc');
        self::assertSame('https://example.test/a/*b*/,}', $values['Name']);
        self::assertSame('a" // text \\', $values['Escaped']);
        $available = [['key' => 'Nested.Value', 'type' => 'integer']];
        $prepared = GameConfigurationAdditions::prepare($content, 'jsonc', ['Nested.Value' => '12'], $available);
        $saved = GameConfigDocument::patch($prepared, 'jsonc', ['Name' => 'New', 'Enabled' => 'false', 'Nested.Value' => '12']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $saved);
        $data = JsonGameConfigDocument::decode($saved);
        self::assertSame('New', $data->Name);
        self::assertFalse($data->Enabled);
        self::assertSame(12, $data->Nested->Value);
        self::assertSame(1, $data->Custom[0]->keep);
        self::assertNull($data->Custom[1]);
        self::assertSame([], $data->Custom[2]);
        self::assertSame($values['Escaped'], $data->Escaped);
    }

    #[DataProvider('invalidDocuments')]
    public function testMalformedDocumentsAreRejected(string $content, string $format): void
    {
        $this->expectException(\JsonException::class);
        GameConfigDocument::values($content, $format);
    }

    public static function invalidDocuments(): iterable
    {
        yield 'strict comments' => ['{/* comment */"Enabled":true}', 'json'];
        yield 'strict trailing comma' => ['{"Enabled":true,}', 'json'];
        yield 'unclosed block comment' => ['{"Enabled":true /* comment }', 'jsonc'];
        yield 'single quoted key' => ["{'Enabled':true}", 'jsonc'];
        yield 'unclosed string' => ['{"Name":"broken}', 'jsonc'];
        yield 'missing comma' => ['{"First":true /* comment */ "Second":false}', 'jsonc'];
        yield 'empty object comma' => ['{,}', 'jsonc'];
        yield 'empty array comma' => ['[,]', 'jsonc'];
        yield 'double comma' => ['{"Enabled":true,,}', 'jsonc'];
    }

    public function testStrictJsonAcceptsTheNativeUtf8Bom(): void
    {
        $content = "\xEF\xBB\xBF".'{"Name":"Original"}';
        self::assertSame(['Name' => 'Original'], GameConfigDocument::values($content, 'json'));
        self::assertSame('New', JsonGameConfigDocument::decode(GameConfigDocument::patch($content, 'json', ['Name' => 'New']))->Name);
    }
}
