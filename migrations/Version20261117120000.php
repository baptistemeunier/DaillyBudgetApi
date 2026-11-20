<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data shared between all the users (household):
 * - a single budget: the most recently changed one is kept, the others are deleted (their lines with them);
 * - categorization rules unique on (pattern, direction) whatever their owner: for duplicates, the most recent rule
 *   is kept; the splits set by the deleted ones keep their category and lose their link to the rule (ON DELETE SET NULL).
 * The owner columns stay: the owner is the user who created the row, the only one who can delete it.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared data: single budget, categorization rules unique on (pattern, direction)';
    }

    public function up(Schema $schema): void
    {
        // budget: keep the most recently changed one
        $this->addSql('DELETE b FROM budget b JOIN budget k ON COALESCE(k.updated_at, k.created_at) > COALESCE(b.updated_at, b.created_at) OR (COALESCE(k.updated_at, k.created_at) = COALESCE(b.updated_at, b.created_at) AND k.id > b.id)');
        // The foreign key on owner_id needs an index once UNIQ_OWNER is dropped
        $this->addSql('CREATE INDEX IDX_73F2F77B7E3C61F9 ON budget (owner_id)');
        $this->addSql('DROP INDEX UNIQ_OWNER ON budget');

        // categorization_rule: keep the most recent rule per (pattern, direction)
        $this->addSql('DELETE r FROM categorization_rule r JOIN categorization_rule k ON k.pattern = r.pattern AND k.direction <=> r.direction AND (k.created_at > r.created_at OR (k.created_at = r.created_at AND k.id > r.id))');
        $this->addSql('DROP INDEX UNIQ_RULE ON categorization_rule');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RULE ON categorization_rule (pattern, direction)');
    }

    public function down(Schema $schema): void
    {
        // The deleted budgets and rules are not restored
        $this->addSql('DROP INDEX UNIQ_RULE ON categorization_rule');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RULE ON categorization_rule (owner_id, pattern, direction)');

        $this->addSql('CREATE UNIQUE INDEX UNIQ_OWNER ON budget (owner_id)');
        $this->addSql('DROP INDEX IDX_73F2F77B7E3C61F9 ON budget');
    }
}
