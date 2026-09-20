<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260920010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add fine-grained access roles and assignments'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE access_roles (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, name VARCHAR(100) NOT NULL, permissions JSON NOT NULL, UNIQUE INDEX uniq_access_role_site_name (site_id, name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE access_role_assignments (id INT AUTO_INCREMENT NOT NULL, role_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_ACCESS_ASSIGN_ROLE (role_id), INDEX IDX_ACCESS_ASSIGN_USER (user_id), UNIQUE INDEX uniq_access_role_user (role_id, user_id), PRIMARY KEY(id), CONSTRAINT FK_ACCESS_ASSIGN_ROLE FOREIGN KEY (role_id) REFERENCES access_roles (id) ON DELETE CASCADE, CONSTRAINT FK_ACCESS_ASSIGN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE access_role_assignments');
        $this->addSql('DROP TABLE access_roles');
    }
}
