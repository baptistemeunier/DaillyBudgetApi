<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Merge of the develop migrations (20261113194753 → 20261114135401).
 */
final class Version20261114150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Categories, transaction splits, account sync history, account display name, connection access expiry';
    }

    public function up(Schema $schema): void
    {
        // Transaction reference is optional
        $this->addSql('ALTER TABLE transaction CHANGE reference reference VARCHAR(255) DEFAULT NULL');

        // Categories (system catalog + user categories)
        $this->addSql('CREATE TABLE category (code VARCHAR(50) DEFAULT NULL, name VARCHAR(100) DEFAULT NULL, type VARCHAR(255) DEFAULT \'expense\' NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, parent_id BINARY(16) DEFAULT NULL, owner_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_CODE (code), INDEX IDX_64C19C1727ACA70 (parent_id), INDEX IDX_64C19C17E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id)');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C17E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');

        // Transaction splits (categorization)
        $this->addSql('CREATE TABLE transaction_split (amount NUMERIC(15, 2) NOT NULL, id BINARY(16) NOT NULL, transaction_id BINARY(16) NOT NULL, category_id BINARY(16) DEFAULT NULL, INDEX IDX_10C64A512FC0CB0F (transaction_id), INDEX IDX_10C64A5112469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE transaction_split ADD CONSTRAINT FK_10C64A512FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction_split ADD CONSTRAINT FK_10C64A5112469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');

        // Account synchronization history
        $this->addSql('CREATE TABLE account_sync (status VARCHAR(255) NOT NULL, error VARCHAR(255) DEFAULT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, account_id BINARY(16) NOT NULL, INDEX IDX_ACCOUNT_SYNC_ACCOUNT_CREATED_AT (account_id, created_at), INDEX IDX_A0B872D09B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_sync ADD CONSTRAINT FK_A0B872D09B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');

        // Custom account name
        $this->addSql('ALTER TABLE account ADD display_name VARCHAR(255) DEFAULT NULL');

        // End of the bank access (End User Agreement)
        $this->addSql('ALTER TABLE account_connection ADD access_expires_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_connection DROP access_expires_at');

        $this->addSql('ALTER TABLE account DROP display_name');

        $this->addSql('ALTER TABLE account_sync DROP FOREIGN KEY FK_A0B872D09B6B5FBA');
        $this->addSql('DROP TABLE account_sync');

        $this->addSql('ALTER TABLE transaction_split DROP FOREIGN KEY FK_10C64A512FC0CB0F');
        $this->addSql('ALTER TABLE transaction_split DROP FOREIGN KEY FK_10C64A5112469DE2');
        $this->addSql('DROP TABLE transaction_split');

        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1727ACA70');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C17E3C61F9');
        $this->addSql('DROP TABLE category');

        $this->addSql('ALTER TABLE transaction CHANGE reference reference VARCHAR(255) NOT NULL');
    }
}
