<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111174708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account (synchronized_at DATETIME DEFAULT NULL, name VARCHAR(255) NOT NULL, iban VARCHAR(255) DEFAULT NULL, bank_id VARCHAR(255) NOT NULL, external_id BINARY(16) DEFAULT NULL, external_status VARCHAR(255) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, account_connection_id BINARY(16) DEFAULT NULL, owner_id BINARY(16) NOT NULL, INDEX IDX_7D3656A492F603B2 (account_connection_id), INDEX IDX_7D3656A47E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account ADD CONSTRAINT FK_7D3656A492F603B2 FOREIGN KEY (account_connection_id) REFERENCES account_connection (id)');
        $this->addSql('ALTER TABLE account ADD CONSTRAINT FK_7D3656A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account DROP FOREIGN KEY FK_7D3656A492F603B2');
        $this->addSql('ALTER TABLE account DROP FOREIGN KEY FK_7D3656A47E3C61F9');
        $this->addSql('DROP TABLE account');
    }
}
