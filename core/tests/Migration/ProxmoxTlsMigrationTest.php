<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261112050000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProxmoxTlsMigrationTest extends TestCase
{
    public function testExistingConnectionsRetainTheirDataAndStartWithVerificationEnabled(): void
    {
        require_once __DIR__.'/../../migrations/Version20261112050000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $db->executeStatement('CREATE TABLE proxmox_clusters (id INTEGER PRIMARY KEY, encrypted_token TEXT NOT NULL)');
            $db->insert('proxmox_clusters', ['id' => 1, 'encrypted_token' => 'synthetic-encrypted-token']);
            $manager = $db->createSchemaManager();
            $before = $manager->introspectSchema();
            $after = clone $before;
            (new Version20261112050000($db, new NullLogger()))->up($after);
            $diff = $manager->createComparator()->compareSchemas($before, $after);
            foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
                $db->executeStatement($sql);
            }
            $row = $db->fetchAssociative('SELECT * FROM proxmox_clusters WHERE id = 1');
            self::assertSame('synthetic-encrypted-token', $row['encrypted_token']);
            self::assertSame(1, (int) $row['verify_tls']);
            $db->insert('proxmox_clusters', ['id' => 2, 'encrypted_token' => 'second-encrypted-token']);
            self::assertSame(1, (int) $db->fetchOne('SELECT verify_tls FROM proxmox_clusters WHERE id = 2'));
        } finally {
            $db->close();
        }
    }
}
