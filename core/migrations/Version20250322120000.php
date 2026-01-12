<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250322120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent bootstrap and registration tokens.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agent_bootstrap_tokens')) {
	    $this->addSql('CREATE TABLE agent_bootstrap_tokens ( id INT AUTO_INCREMENT NOT NULL,  created_by_id INT DEFAULT NULL,  name VARCHAR(190) NOT NULL,  token_prefix VARCHAR(16) NOT NULL,  token_hash VARCHAR(64) NOT NULL,  encrypted_token JSON NOT NULL,  bound_cidr VARCHAR(64) DEFAULT NULL,  bound_node_name VARCHAR(190) DEFAULT NULL,  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',  revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',  INDEX IDX_BOOTSTRAP_TOKENS_HASH (token_hash),  INDEX IDX_BOOTSTRAP_TOKENS_CREATED_BY (created_by_id),  PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE agent_bootstrap_tokens ADD CONSTRAINT FK_BOOTSTRAP_TOKENS_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('agent_registration_tokens')) {
	    $this->addSql('CREATE TABLE agent_registration_tokens ( id INT AUTO_INCREMENT NOT NULL,  bootstrap_token_id INT DEFAULT NULL,  agent_id VARCHAR(64) NOT NULL,  token_prefix VARCHAR(16) NOT NULL,  token_hash VARCHAR(64) NOT NULL,  encrypted_token JSON NOT NULL,  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',  INDEX IDX_REGISTRATION_TOKENS_HASH (token_hash),  INDEX IDX_REGISTRATION_TOKENS_BOOTSTRAP (bootstrap_token_id),  INDEX IDX_REGISTRATION_TOKENS_AGENT (agent_id),  PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE agent_registration_tokens ADD CONSTRAINT FK_REGISTRATION_TOKENS_BOOTSTRAP FOREIGN KEY (bootstrap_token_id) REFERENCES agent_bootstrap_tokens (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('agent_registration_tokens')) {
            $this->addSql('DROP TABLE agent_registration_tokens');
        }

        if ($schema->hasTable('agent_bootstrap_tokens')) {
            $this->addSql('DROP TABLE agent_bootstrap_tokens');
        }
    }
}
