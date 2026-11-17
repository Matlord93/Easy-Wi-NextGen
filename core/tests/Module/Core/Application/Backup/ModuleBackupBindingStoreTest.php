<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Resource\ModuleBackupBindingStore;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261118010000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ModuleBackupBindingStoreTest extends TestCase
{
    public function testBindingMigrationAndEncryptedDurableUpdate(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 5).'/migrations/Version20261118010000.php';
        $schema = new Schema();
        (new Version20261118010000($connection, new NullLogger()))->up($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        $crypto = new SecretsCrypto('binding-test');
        $store = new ModuleBackupBindingStore($connection, $crypto);
        $binding = ['agent_id' => 'private-agent', 'path' => '/var/vmail/private-domain', 'service' => 'mail-private'];
        $store->save('mail', '42', $binding);
        self::assertStringNotContainsString('private-domain', $connection->fetchOne('SELECT payload FROM backup_module_bindings'));
        self::assertSame($binding, (new ModuleBackupBindingStore($connection, $crypto))->find('mail', '42'));
        $binding['path'] = '/var/vmail/updated-domain';
        $store->save('mail', '42', $binding);
        self::assertSame($binding, $store->find('mail', '42'));
        self::assertNull($store->find('mail', '43'));
        $connection->close();
    }

    public function testRootAndTraversalPathsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleBackupBindingStore::validate(['agent_id' => 'node', 'path' => '/var/vmail/../etc']);
    }
}
