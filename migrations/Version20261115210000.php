<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Merge of the develop migrations (20261114162214 → 20261115200000).
 */
final class Version20261115210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Budget, account balance/type/low balance threshold, manual accounts, savings targets, category icon, categorization rules, notifications, push subscriptions, notification settings';
    }

    public function up(Schema $schema): void
    {
        // Monthly budget per user
        $this->addSql('CREATE TABLE budget (amount NUMERIC(15, 2) NOT NULL, currency VARCHAR(255) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_OWNER (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE budget_line (amount NUMERIC(15, 2) NOT NULL, id BINARY(16) NOT NULL, budget_id BINARY(16) NOT NULL, category_id BINARY(16) NOT NULL, INDEX IDX_ABD0B6A636ABA6B8 (budget_id), INDEX IDX_ABD0B6A612469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE budget ADD CONSTRAINT FK_73F2F77B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE budget_line ADD CONSTRAINT FK_ABD0B6A636ABA6B8 FOREIGN KEY (budget_id) REFERENCES budget (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE budget_line ADD CONSTRAINT FK_ABD0B6A612469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');

        // Account: bank balance, type, manual accounts (no bank), low balance threshold
        $this->addSql('ALTER TABLE account ADD balance NUMERIC(15, 2) DEFAULT NULL, ADD balance_currency VARCHAR(255) DEFAULT NULL, ADD balance_type VARCHAR(50) DEFAULT NULL, ADD balance_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD type VARCHAR(20) DEFAULT \'checking\' NOT NULL');
        $this->addSql('ALTER TABLE account CHANGE bank_id bank_id VARCHAR(255) DEFAULT NULL, CHANGE synchronized_status synchronized_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD low_balance_threshold NUMERIC(15, 2) DEFAULT NULL');

        // Manual transactions and mirror transactions
        $this->addSql('ALTER TABLE transaction CHANGE external_id external_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_split ADD counterpart_transaction_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_split ADD CONSTRAINT FK_10C64A51E991DB2B FOREIGN KEY (counterpart_transaction_id) REFERENCES transaction (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_10C64A51E991DB2B ON transaction_split (counterpart_transaction_id)');

        // Category: savings target, icon
        $this->addSql('ALTER TABLE category ADD target_amount NUMERIC(15, 2) DEFAULT NULL, ADD target_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE category ADD icon VARCHAR(50) DEFAULT NULL');

        // Categorization rules, merchant key, split source
        $this->addSql('CREATE TABLE categorization_rule (pattern VARCHAR(100) NOT NULL, direction VARCHAR(255) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, category_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_RULE (owner_id, pattern, direction), INDEX IDX_F056C98A7E3C61F9 (owner_id), INDEX IDX_F056C98A12469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE categorization_rule ADD CONSTRAINT FK_F056C98A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categorization_rule ADD CONSTRAINT FK_F056C98A12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction ADD merchant_key VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_MERCHANT_KEY ON transaction (merchant_key)');
        $this->addSql('ALTER TABLE transaction_split ADD source VARCHAR(255) DEFAULT \'manual\' NOT NULL, ADD rule_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_split ADD CONSTRAINT FK_10C64A51744E0351 FOREIGN KEY (rule_id) REFERENCES categorization_rule (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_10C64A51744E0351 ON transaction_split (rule_id)');

        // Notifications (inbox) and Web Push subscriptions
        $this->addSql('CREATE TABLE notification (type VARCHAR(50) NOT NULL, data JSON NOT NULL, dedup_key VARCHAR(255) NOT NULL, read_at DATETIME DEFAULT NULL, pushed_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, account_id BINARY(16) DEFAULT NULL, account_connection_id BINARY(16) DEFAULT NULL, transaction_id BINARY(16) DEFAULT NULL, category_id BINARY(16) DEFAULT NULL, INDEX IDX_NOTIFICATION_OWNER_CREATED_AT (owner_id, created_at), UNIQUE INDEX UNIQ_NOTIFICATION_DEDUP_KEY (owner_id, dedup_key), INDEX IDX_BF5476CA7E3C61F9 (owner_id), INDEX IDX_BF5476CA9B6B5FBA (account_id), INDEX IDX_BF5476CA92F603B2 (account_connection_id), INDEX IDX_BF5476CA2FC0CB0F (transaction_id), INDEX IDX_BF5476CA12469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE push_subscription (id BINARY(16) NOT NULL, endpoint LONGTEXT NOT NULL, p256dh VARCHAR(255) NOT NULL, auth VARCHAR(255) NOT NULL, locale VARCHAR(5) NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, INDEX IDX_562830F37E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA92F603B2 FOREIGN KEY (account_connection_id) REFERENCES account_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA2FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F37E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');

        // Notification settings (one per user)
        $this->addSql('CREATE TABLE notification_settings (push JSON NOT NULL, large_transaction_threshold NUMERIC(15, 2) NOT NULL, hide_amounts TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_OWNER (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification_settings ADD CONSTRAINT FK_B05598607E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_settings DROP FOREIGN KEY FK_B05598607E3C61F9');
        $this->addSql('DROP TABLE notification_settings');

        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA7E3C61F9');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA9B6B5FBA');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA92F603B2');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA2FC0CB0F');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA12469DE2');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F37E3C61F9');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE push_subscription');

        $this->addSql('ALTER TABLE transaction_split DROP FOREIGN KEY FK_10C64A51744E0351');
        $this->addSql('DROP INDEX IDX_10C64A51744E0351 ON transaction_split');
        $this->addSql('ALTER TABLE transaction_split DROP source, DROP rule_id');
        $this->addSql('DROP INDEX IDX_MERCHANT_KEY ON transaction');
        $this->addSql('ALTER TABLE transaction DROP merchant_key');
        $this->addSql('ALTER TABLE categorization_rule DROP FOREIGN KEY FK_F056C98A7E3C61F9');
        $this->addSql('ALTER TABLE categorization_rule DROP FOREIGN KEY FK_F056C98A12469DE2');
        $this->addSql('DROP TABLE categorization_rule');

        $this->addSql('ALTER TABLE category DROP target_amount, DROP target_date, DROP icon');

        $this->addSql('ALTER TABLE transaction_split DROP FOREIGN KEY FK_10C64A51E991DB2B');
        $this->addSql('DROP INDEX IDX_10C64A51E991DB2B ON transaction_split');
        $this->addSql('ALTER TABLE transaction_split DROP counterpart_transaction_id');
        $this->addSql('ALTER TABLE transaction CHANGE external_id external_id VARCHAR(255) NOT NULL');

        $this->addSql('ALTER TABLE account DROP balance, DROP balance_currency, DROP balance_type, DROP balance_at, DROP type, DROP low_balance_threshold');
        $this->addSql('ALTER TABLE account CHANGE bank_id bank_id VARCHAR(255) NOT NULL, CHANGE synchronized_status synchronized_status VARCHAR(255) NOT NULL');

        $this->addSql('ALTER TABLE budget DROP FOREIGN KEY FK_73F2F77B7E3C61F9');
        $this->addSql('ALTER TABLE budget_line DROP FOREIGN KEY FK_ABD0B6A636ABA6B8');
        $this->addSql('ALTER TABLE budget_line DROP FOREIGN KEY FK_ABD0B6A612469DE2');
        $this->addSql('DROP TABLE budget');
        $this->addSql('DROP TABLE budget_line');
    }
}
