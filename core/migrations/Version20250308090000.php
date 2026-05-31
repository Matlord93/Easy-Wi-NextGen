<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250308090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add gameserver profiles, slot enforcement, and port allocations.';
    }

    public function up(Schema $schema): void
    {
        // port_pools is created in Version20250311123000 (after this migration's timestamp).
        // For fresh databases, the columns are baked into the CREATE TABLE there.
        // For existing databases where port_pools already exists without these columns, add them here.
        if ($schema->hasTable('port_pools')) {
            $ppTable = $schema->getTable('port_pools');
            if (!$ppTable->hasColumn('tag')) {
                $this->addSql('ALTER TABLE port_pools ADD tag VARCHAR(120) NOT NULL DEFAULT \'\'');
                $this->addSql('UPDATE port_pools SET tag = name WHERE tag = \'\' OR tag IS NULL');
            }
            if (!$ppTable->hasColumn('enabled')) {
                $this->addSql('ALTER TABLE port_pools ADD enabled TINYINT(1) NOT NULL DEFAULT 1');
            }
        }

        $this->addSql('ALTER TABLE instances ADD max_slots INT NOT NULL DEFAULT 16');
        $this->addSql('ALTER TABLE instances ADD current_slots INT NOT NULL DEFAULT 16');
        $this->addSql('ALTER TABLE instances ADD lock_slots TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('UPDATE instances SET max_slots = slots, current_slots = slots WHERE max_slots = 16 AND current_slots = 16');

        $this->addSql('CREATE TABLE game_profiles (id INT AUTO_INCREMENT NOT NULL, game_key VARCHAR(120) NOT NULL, enforce_mode_ports VARCHAR(40) NOT NULL, enforce_mode_slots VARCHAR(40) NOT NULL, port_roles JSON NOT NULL, slot_rules JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_game_profiles_key (game_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE port_allocations (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, role_key VARCHAR(80) NOT NULL, proto VARCHAR(6) NOT NULL, port INT NOT NULL, pool_tag VARCHAR(120) DEFAULT NULL, purpose VARCHAR(120) DEFAULT NULL, allocation_strategy VARCHAR(40) NOT NULL, required TINYINT(1) NOT NULL, derived_from_role_key VARCHAR(80) DEFAULT NULL, derived_offset INT DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_host_free TINYINT(1) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_allocations_instance (instance_id), INDEX idx_port_allocations_node (node_id), UNIQUE INDEX uniq_node_proto_port (node_id, proto, port), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE port_allocations ADD CONSTRAINT fk_port_allocations_instance FOREIGN KEY (instance_id) REFERENCES instances (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE port_allocations ADD CONSTRAINT fk_port_allocations_node FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE port_allocations DROP FOREIGN KEY fk_port_allocations_instance');
        $this->addSql('ALTER TABLE port_allocations DROP FOREIGN KEY fk_port_allocations_node');
        $this->addSql('DROP TABLE port_allocations');
        $this->addSql('DROP TABLE game_profiles');
        $this->addSql('ALTER TABLE instances DROP max_slots');
        $this->addSql('ALTER TABLE instances DROP current_slots');
        $this->addSql('ALTER TABLE instances DROP lock_slots');
        if ($schema->hasTable('port_pools')) {
            $ppTable = $schema->getTable('port_pools');
            if ($ppTable->hasColumn('tag')) {
                $this->addSql('ALTER TABLE port_pools DROP tag');
            }
            if ($ppTable->hasColumn('enabled')) {
                $this->addSql('ALTER TABLE port_pools DROP enabled');
            }
        }
    }
}
