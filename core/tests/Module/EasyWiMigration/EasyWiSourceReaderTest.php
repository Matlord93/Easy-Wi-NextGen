<?php

declare(strict_types=1);

namespace App\Tests\Module\EasyWiMigration;

use App\Module\EasyWiMigration\Application\EasyWiSourceReader;
use PHPUnit\Framework\TestCase;

final class EasyWiSourceReaderTest extends TestCase
{
    public function testLegacyRowsAreFetchedAsTypedListsWithIntegerPagination(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE easywi_users (id INTEGER PRIMARY KEY, email TEXT, password TEXT, firstname TEXT, lastname TEXT, company TEXT, street TEXT, zip TEXT, city TEXT, country TEXT, phone TEXT, created TEXT, last_login TEXT, language TEXT, reseller_id INTEGER, type TEXT)');
        $pdo->exec("INSERT INTO easywi_users (id, email, type) VALUES (1, 'first@example.test', 'customer'), (2, 'second@example.test', 'admin')");
        $reader = (new \ReflectionClass(EasyWiSourceReader::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(EasyWiSourceReader::class, 'pdo'))->setValue($reader, $pdo);
        (new \ReflectionProperty(EasyWiSourceReader::class, 'prefix'))->setValue($reader, 'easywi_');

        $rows = $reader->fetchUsers(1, 1);

        self::assertSame([0], array_keys($rows));
        self::assertSame(2, $rows[0]['id']);
        self::assertSame('second@example.test', $rows[0]['email']);
        self::assertNull($rows[0]['company']);
        self::assertSame(2, $reader->countTable('users'));
        self::assertTrue($reader->tableExists('users'));
        self::assertFalse($reader->tableExists('not_present'));
    }
}
