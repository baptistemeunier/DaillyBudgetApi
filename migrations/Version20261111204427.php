<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111204427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction (external_id VARCHAR(255) NOT NULL, reference VARCHAR(255) NOT NULL, description VARCHAR(255) NOT NULL, detail LONGTEXT DEFAULT NULL, amount NUMERIC(15, 2) NOT NULL, currency VARCHAR(255) NOT NULL, booking_at DATETIME DEFAULT NULL, value_at DATETIME DEFAULT NULL, external_created_at DATETIME DEFAULT NULL, is_booked TINYINT NOT NULL, external_transaction_type VARCHAR(255) DEFAULT NULL, external_transaction_code VARCHAR(255) DEFAULT NULL, raw_data JSON DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, account_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_EXTERNAL_ID (account_id, external_id), INDEX IDX_723705D19B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D19B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id)');
        $this->addSql('ALTER TABLE account ADD external_updated_at DATETIME DEFAULT NULL, ADD synchronized_status VARCHAR(255) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EXTERNAL_ID ON account (external_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EXTERNAL_ID ON account_connection (external_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EXTERNAL_ID ON user (username)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D19B6B5FBA');
        $this->addSql('DROP TABLE transaction');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP INDEX UNIQ_EXTERNAL_ID ON account');
        $this->addSql('ALTER TABLE account DROP external_updated_at, DROP synchronized_status');
        $this->addSql('DROP INDEX UNIQ_EXTERNAL_ID ON account_connection');
        $this->addSql('DROP INDEX UNIQ_EXTERNAL_ID ON user');
    }
}
