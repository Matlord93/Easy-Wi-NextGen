<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118061000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record opening wallet balances including previously debited but unsettled usage without rewriting historical credits.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $reference = $this->connection->getDatabasePlatform()->getConcatExpression("'opening:'", 'a.id');
        $this->addSql("INSERT INTO wallet_entries (account_id, kind, units, reference, description, created_at) SELECT a.id, 'opening', a.balance_units + COALESCE((SELECT SUM(c.pending_units) FROM shop_contracts c WHERE c.account_id = a.id AND c.billing_mode = 'wallet'), 0), $reference, ?, ? FROM rental_accounts a WHERE NOT EXISTS (SELECT 1 FROM wallet_entries e WHERE e.reference = $reference)", ['Übernommenes Guthaben einschließlich bereits abgezogener, noch abzurechnender Nutzung', $at]);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Opening financial entries are retained.');
    }
}
