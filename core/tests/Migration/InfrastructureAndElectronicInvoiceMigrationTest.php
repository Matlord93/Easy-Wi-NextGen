<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261113010000;
use DoctrineMigrations\Version20261113020000;
use DoctrineMigrations\Version20261113030000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InfrastructureAndElectronicInvoiceMigrationTest extends TestCase
{
    public function testUpgradePreservesExistingInvoicesAssignmentsAndLeavesUnknownOwnershipDatesNull(): void
    {
        require_once __DIR__.'/../../migrations/Version20261113010000.php';
        require_once __DIR__.'/../../migrations/Version20261113020000.php';
        require_once __DIR__.'/../../migrations/Version20261113030000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $db->executeStatement('CREATE TABLE invoices (id INTEGER PRIMARY KEY, number VARCHAR(40) NOT NULL)');
            $db->executeStatement('CREATE TABLE kvm_guest_assignments (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL)');
            $db->executeStatement('CREATE TABLE proxmox_guest_assignments (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL)');
            $db->insert('invoices', ['id' => 1, 'number' => 'LEGACY-2026']);
            foreach (['kvm_guest_assignments', 'proxmox_guest_assignments'] as $table) {
                $db->insert($table, ['id' => 1, 'customer_id' => 12]);
            }
            $manager = $db->createSchemaManager();
            $before = $manager->introspectSchema();
            $after = clone $before;
            (new Version20261113010000($db, new NullLogger()))->up($after);
            (new Version20261113020000($db, new NullLogger()))->up($after);
            (new Version20261113030000($db, new NullLogger()))->up($after);
            $diff = $manager->createComparator()->compareSchemas($before, $after);
            foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
                $db->executeStatement($sql);
            }
            self::assertSame('LEGACY-2026', $db->fetchOne('SELECT number FROM invoices WHERE id = 1'));
            foreach (['kvm_guest_assignments', 'proxmox_guest_assignments'] as $table) {
                self::assertSame(['customer_id' => 12, 'assigned_at' => null], $db->fetchAssociative('SELECT customer_id, assigned_at FROM '.$table.' WHERE id = 1'));
            }
            self::assertTrue($manager->tablesExist(['infrastructure_samples', 'infrastructure_monitor_policies', 'infrastructure_rollups', 'electronic_invoice_archives']));
            $db->insert('electronic_invoice_archives', ['invoice_id' => 1, 'format' => 'xrechnung', 'contents' => '<synthetic/>', 'sha256' => hash('sha256', '<synthetic/>'), 'validation_version' => 'migration-test', 'created_at' => '2026-11-13 00:00:00']);
            self::assertSame('<synthetic/>', $db->fetchOne('SELECT contents FROM electronic_invoice_archives WHERE invoice_id = 1'));
        } finally {
            $db->close();
        }
    }
}
