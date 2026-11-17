<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use App\Infrastructure\Doctrine\EmptyJsonDefault;
use App\Infrastructure\Doctrine\JsonDefaultsSchemaManager;
use App\Infrastructure\Doctrine\JsonDefaultsSchemaManagerFactory;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MySqlJsonDefaultsTest extends TestCase
{
    public static function introspectedDefaults(): iterable
    {
        yield 'object literal' => ['{}', '{}'];
        yield 'array literal' => ['[]', '[]'];
        yield 'object character set' => ['{}', "_utf8mb4'{}'"];
        yield 'array character set' => ['[]', "_utf8mb4'[]'"];
        yield 'escaped object' => ['{}', "_utf8mb4\\'{}\\'"];
        yield 'escaped array expression' => ['[]', "(_utf8mb4\\'[]\\')"];
    }

    #[DataProvider('introspectedDefaults')]
    public function testMysqlExpressionDefaultsRoundTripWithoutSchemaDrift(string $literal, string $introspected): void
    {
        $platform = new MySQLPlatform();
        $expected = Column::editor()->setUnquotedName('config')->setTypeName('json')->setDefaultValue(EmptyJsonDefault::fromValue($literal))->create();
        $actual = Column::editor()->setUnquotedName('config')->setTypeName('json')->setDefaultValue(EmptyJsonDefault::fromValue($introspected))->create();

        self::assertTrue($platform->columnsEqual($actual, $expected));
        self::assertStringContainsString("DEFAULT ('".$literal."')", $platform->getColumnDeclarationSQL('config', $expected->toArray(true)));
    }

    public function testDifferentJsonDefaultsRemainVisibleToSchemaComparison(): void
    {
        $platform = new MySQLPlatform();
        $object = Column::editor()->setUnquotedName('config')->setTypeName('json')->setDefaultValue(EmptyJsonDefault::fromValue('{}'))->create();
        $array = Column::editor()->setUnquotedName('config')->setTypeName('json')->setDefaultValue(EmptyJsonDefault::fromValue('[]'))->create();
        $none = Column::editor()->setUnquotedName('config')->setTypeName('json')->create();

        self::assertFalse($platform->columnsEqual($object, $array));
        self::assertFalse($platform->columnsEqual($object, $none));
        self::assertStringContainsString("DEFAULT '{}'", $platform->getDefaultValueDeclarationSQL(['typeName' => 'string', 'default' => '{}']));
    }

    public function testSchemaManagerFactoryPreservesNativeDatabasePlatforms(): void
    {
        $config = new Configuration();
        $factory = new JsonDefaultsSchemaManagerFactory();
        $connections = [];
        foreach ([['pdo_mysql', '8.4.13'], ['pdo_mysql', '10.11.19-MariaDB'], ['pdo_mysql', '8.0.40'], ['pdo_sqlite', '3.40.1']] as [$driver, $version]) {
            $native = DriverManager::getConnection(['driver' => $driver, 'serverVersion' => $version], $config);
            $connection = $this->createStub(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($native->getDatabasePlatform());
            $connection->method('getServerVersion')->willReturn($version);
            $connections[] = $connection;
        }
        [$mysql, $maria, $older, $sqlite] = $connections;

        $nativePlatform = $mysql->getDatabasePlatform();
        self::assertInstanceOf(JsonDefaultsSchemaManager::class, $factory->createSchemaManager($mysql));
        self::assertSame($nativePlatform, $mysql->getDatabasePlatform());
        self::assertInstanceOf(MariaDBPlatform::class, $maria->getDatabasePlatform());
        self::assertNotInstanceOf(JsonDefaultsSchemaManager::class, $factory->createSchemaManager($older));
        self::assertNotInstanceOf(JsonDefaultsSchemaManager::class, $factory->createSchemaManager($maria));
        self::assertInstanceOf(SQLitePlatform::class, $sqlite->getDatabasePlatform());
    }
}
