<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115163502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification (type VARCHAR(50) NOT NULL, data JSON NOT NULL, dedup_key VARCHAR(255) NOT NULL, read_at DATETIME DEFAULT NULL, pushed_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, account_id BINARY(16) DEFAULT NULL, account_connection_id BINARY(16) DEFAULT NULL, transaction_id BINARY(16) DEFAULT NULL, category_id BINARY(16) DEFAULT NULL, INDEX IDX_NOTIFICATION_OWNER_CREATED_AT (owner_id, created_at), UNIQUE INDEX UNIQ_NOTIFICATION_DEDUP_KEY (owner_id, dedup_key), INDEX IDX_BF5476CA7E3C61F9 (owner_id), INDEX IDX_BF5476CA9B6B5FBA (account_id), INDEX IDX_BF5476CA92F603B2 (account_connection_id), INDEX IDX_BF5476CA2FC0CB0F (transaction_id), INDEX IDX_BF5476CA12469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE push_subscription (id BINARY(16) NOT NULL, endpoint LONGTEXT NOT NULL, p256dh VARCHAR(255) NOT NULL, auth VARCHAR(255) NOT NULL, locale VARCHAR(5) NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, INDEX IDX_562830F37E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA92F603B2 FOREIGN KEY (account_connection_id) REFERENCES account_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA2FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F37E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA7E3C61F9');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA9B6B5FBA');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA92F603B2');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA2FC0CB0F');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA12469DE2');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F37E3C61F9');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE push_subscription');
    }
}
