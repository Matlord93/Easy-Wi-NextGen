<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250217120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status components, maintenance windows, incidents, and incident updates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE status_components (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, name VARCHAR(160) NOT NULL, type VARCHAR(40) NOT NULL, target_ref VARCHAR(255) NOT NULL, status VARCHAR(40) NOT NULL, last_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_status_components_site_id (site_id), INDEX idx_status_components_visibility (visible_public), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE maintenance_windows (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, message LONGTEXT DEFAULT NULL, start_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', end_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_maintenance_windows_site_id (site_id), INDEX idx_maintenance_windows_visibility (visible_public), INDEX idx_maintenance_windows_start_end (start_at, end_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE maintenance_window_components (maintenance_window_id INT NOT NULL, status_component_id INT NOT NULL, INDEX IDX_9C8E50B7FB56D6 (maintenance_window_id), INDEX IDX_9C8E50CB7CB403 (status_component_id), PRIMARY KEY(maintenance_window_id, status_component_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE incidents (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, status VARCHAR(40) NOT NULL, message LONGTEXT DEFAULT NULL, started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', resolved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_incidents_site_id (site_id), INDEX idx_incidents_visibility (visible_public), INDEX idx_incidents_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE incident_components (incident_id INT NOT NULL, status_component_id INT NOT NULL, INDEX IDX_5B0588B8D19DCC98 (incident_id), INDEX IDX_5B0588B7CB403 (status_component_id), PRIMARY KEY(incident_id, status_component_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE incident_updates (id INT AUTO_INCREMENT NOT NULL, incident_id INT NOT NULL, created_by_id INT NOT NULL, status VARCHAR(40) NOT NULL, message LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_incident_updates_incident_id (incident_id), INDEX IDX_925C5D8EB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE maintenance_window_components ADD CONSTRAINT FK_9C8E50B7FB56D6 FOREIGN KEY (maintenance_window_id) REFERENCES maintenance_windows (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE maintenance_window_components ADD CONSTRAINT FK_9C8E50CB7CB403 FOREIGN KEY (status_component_id) REFERENCES status_components (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_components ADD CONSTRAINT FK_5B0588B8D19DCC98 FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_components ADD CONSTRAINT FK_5B0588B7CB403 FOREIGN KEY (status_component_id) REFERENCES status_components (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_updates ADD CONSTRAINT FK_925C5D8ED19DCC98 FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_updates ADD CONSTRAINT FK_925C5D8EB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maintenance_window_components DROP FOREIGN KEY FK_9C8E50B7FB56D6');
        $this->addSql('ALTER TABLE maintenance_window_components DROP FOREIGN KEY FK_9C8E50CB7CB403');
        $this->addSql('ALTER TABLE incident_components DROP FOREIGN KEY FK_5B0588B8D19DCC98');
        $this->addSql('ALTER TABLE incident_components DROP FOREIGN KEY FK_5B0588B7CB403');
        $this->addSql('ALTER TABLE incident_updates DROP FOREIGN KEY FK_925C5D8ED19DCC98');
        $this->addSql('ALTER TABLE incident_updates DROP FOREIGN KEY FK_925C5D8EB03A8386');
        $this->addSql('DROP TABLE incident_updates');
        $this->addSql('DROP TABLE incident_components');
        $this->addSql('DROP TABLE incidents');
        $this->addSql('DROP TABLE maintenance_window_components');
        $this->addSql('DROP TABLE maintenance_windows');
        $this->addSql('DROP TABLE status_components');
    }
}
