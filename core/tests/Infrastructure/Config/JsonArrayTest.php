<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Config;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonArrayTest extends TestCase
{
    public function testListsAndObjectKeysArePreserved(): void
    {
        self::assertSame([1, false, null, ['name' => 'test']], JsonArray::decode('[1,false,null,{"name":"test"}]'));
        self::assertSame(['enabled' => false, 0 => 'numeric', 'data' => [1, null]], JsonArray::decode('{"enabled":false,"0":"numeric","data":[1,null]}'));
        self::assertSame([], JsonArray::decode('{}'));
        self::assertSame([], JsonArray::decode('[]'));
    }

    public function testStringKeyedMapsPreserveStructuredValuesAndRejectScalarRoots(): void
    {
        $value = ['enabled' => false, 'nested' => ['items' => [1, null]], 12 => 'numeric'];
        self::assertSame(['enabled' => false, 'nested' => ['items' => [1, null]]], JsonMap::fromValue($value));
        self::assertNull(JsonMap::fromValue('not an object'));
        self::assertSame([], JsonMap::fromValue([]));
    }

    #[DataProvider('nonArrays')]
    public function testScalarsAndInvalidJsonAreRejected(string $json): void
    {
        self::assertNull(JsonArray::decode($json));
    }

    /** @return iterable<string, array{string}> */
    public static function nonArrays(): iterable
    {
        yield 'null' => ['null'];
        yield 'number' => ['42'];
        yield 'boolean' => ['true'];
        yield 'string' => ['"text"'];
        yield 'malformed' => ['{broken}'];
    }
}
