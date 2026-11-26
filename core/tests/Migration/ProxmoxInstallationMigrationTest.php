<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DoctrineMigrations\Version20261121090000;
use DoctrineMigrations\Version20261121110000;
use DoctrineMigrations\Version20261123120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProxmoxInstallationMigrationTest extends TestCase
{
    public function testPasswordHandoffUpgradeKeepsExistingVmOwners(): void
    {
        require_once __DIR__.'/../../migrations/Version20261123120000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            foreach (['kvm_guest_assignments', 'proxmox_guest_assignments'] as $table) {
                $db->executeStatement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL)');
                $db->insert($table, ['id' => 15, 'customer_id' => 25]);
            }
            $schema = $db->createSchemaManager();
            $before = $schema->introspectSchema();
            $after = clone $before;
            (new Version20261123120000($db, new NullLogger()))->up($after);
            foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($schema->createComparator()->compareSchemas($before, $after)) as $sql) {
                $db->executeStatement($sql);
            }
            foreach (['kvm_guest_assignments', 'proxmox_guest_assignments'] as $table) {
                self::assertSame(['customer_id' => 25, 'root_password_handoff' => null], $db->fetchAssociative('SELECT customer_id, root_password_handoff FROM '.$table.' WHERE id = 15'));
            }
        } finally {
            $db->close();
        }
    }

    public function testAddressPoolUpgradeKeepsExistingTasksAndForbidsDuplicateLeases(): void
    {
        require_once __DIR__.'/../../migrations/Version20261121110000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $db->executeStatement('CREATE TABLE proxmox_clusters (id INTEGER PRIMARY KEY)');
            $db->executeStatement('CREATE TABLE proxmox_tasks (id INTEGER PRIMARY KEY)');
            $db->insert('proxmox_clusters', ['id' => 1]);
            $db->insert('proxmox_tasks', ['id' => 10]);
            $db->insert('proxmox_tasks', ['id' => 11]);
            $schema = $db->createSchemaManager();
            $before = $schema->introspectSchema();
            $after = clone $before;
            (new Version20261121110000($db, new NullLogger()))->up($after);
            foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($schema->createComparator()->compareSchemas($before, $after)) as $sql) {
                $db->executeStatement($sql);
            }
            $network = ['family' => 4, 'prefix' => 24, 'gateway' => '192.0.2.1', 'bridge' => 'vmbr0'];
            $db->insert('proxmox_ip_pools', ['cluster_id' => 1, 'node' => 'pve-a', 'first_address' => '192.0.2.10', 'last_address' => '192.0.2.20', 'enabled' => 1] + $network);
            $db->insert('proxmox_ip_leases', ['task_id' => 10, 'address_key' => 'c000020a'] + $network);
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM proxmox_tasks'));
            $this->expectException(UniqueConstraintViolationException::class);
            $db->insert('proxmox_ip_leases', ['task_id' => 11, 'address_key' => 'c000020a'] + $network);
        } finally {
            $db->close();
        }
    }

    public function testUpgradeKeepsLegacyTasksAndReservesNewIdsAcrossGuestKinds(): void
    {
        require_once __DIR__.'/../../migrations/Version20261121090000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $db->executeStatement('CREATE TABLE proxmox_clusters (id INTEGER PRIMARY KEY)');
            $db->executeStatement('CREATE TABLE proxmox_tasks (id INTEGER PRIMARY KEY, cluster_id INTEGER NOT NULL, kind VARCHAR(10) NOT NULL)');
            $db->insert('proxmox_tasks', ['id' => 1, 'cluster_id' => 1, 'kind' => 'qemu']);
            $schema = $db->createSchemaManager();
            $before = $schema->introspectSchema();
            $after = clone $before;
            (new Version20261121090000($db, new NullLogger()))->up($after);
            foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($schema->createComparator()->compareSchemas($before, $after)) as $sql) {
                $db->executeStatement($sql);
            }
            self::assertNull($db->fetchOne('SELECT target_lock FROM proxmox_tasks WHERE id = 1'));
            $db->insert('proxmox_tasks', ['id' => 2, 'cluster_id' => 1, 'kind' => 'qemu', 'target_lock' => 103]);
            $db->insert('proxmox_tasks', ['id' => 3, 'cluster_id' => 2, 'kind' => 'lxc', 'target_lock' => 103]);
            $this->expectException(UniqueConstraintViolationException::class);
            $db->insert('proxmox_tasks', ['id' => 4, 'cluster_id' => 1, 'kind' => 'lxc', 'target_lock' => 103]);
        } finally {
            $db->close();
        }
    }
}
