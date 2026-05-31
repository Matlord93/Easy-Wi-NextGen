<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260701090500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UniFi module tables.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('unifi_settings')) {
            $this->addSql('CREATE TABLE unifi_settings (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT(1) NOT NULL, base_url VARCHAR(255) DEFAULT NULL, username VARCHAR(120) DEFAULT NULL, password_encrypted JSON DEFAULT NULL, verify_tls TINYINT(1) NOT NULL, site VARCHAR(80) DEFAULT NULL, node_targets JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$this->tableExists('unifi_policy')) {
            $this->addSql('CREATE TABLE unifi_policy (id INT AUTO_INCREMENT NOT NULL, mode VARCHAR(20) NOT NULL, allowed_ports JSON DEFAULT NULL, allowed_ranges JSON DEFAULT NULL, allowed_protocols JSON DEFAULT NULL, allowed_tags JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$this->tableExists('unifi_manual_rules')) {
            $this->addSql('CREATE TABLE unifi_manual_rules (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(160) NOT NULL, protocol VARCHAR(6) NOT NULL, port INT NOT NULL, target_ip VARCHAR(45) NOT NULL, target_port INT NOT NULL, enabled TINYINT(1) NOT NULL, description TEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$this->tableExists('unifi_port_mappings')) {
            $this->addSql('CREATE TABLE unifi_port_mappings (id INT AUTO_INCREMENT NOT NULL, rule_name VARCHAR(200) NOT NULL, rule_type VARCHAR(20) NOT NULL, port INT NOT NULL, protocol VARCHAR(6) NOT NULL, target_ip VARCHAR(45) NOT NULL, target_port INT NOT NULL, unifi_rule_id VARCHAR(120) DEFAULT NULL, last_sync_status VARCHAR(40) DEFAULT NULL, last_error TEXT DEFAULT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_unifi_rule_name (rule_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$this->tableExists('unifi_audit_log')) {
            $this->addSql('CREATE TABLE unifi_audit_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(120) NOT NULL, status VARCHAR(40) NOT NULL, request_id VARCHAR(64) DEFAULT NULL, error TEXT DEFAULT NULL, context JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->tableExists('unifi_audit_log')) {
            $this->addSql('DROP TABLE unifi_audit_log');
        }

        if ($this->tableExists('unifi_port_mappings')) {
            $this->addSql('DROP TABLE unifi_port_mappings');
        }

        if ($this->tableExists('unifi_manual_rules')) {
            $this->addSql('DROP TABLE unifi_manual_rules');
        }

        if ($this->tableExists('unifi_policy')) {
            $this->addSql('DROP TABLE unifi_policy');
        }

        if ($this->tableExists('unifi_settings')) {
            $this->addSql('DROP TABLE unifi_settings');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }
}
