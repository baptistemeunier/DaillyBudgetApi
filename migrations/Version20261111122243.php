<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111122243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account_connection (bank_id VARCHAR(50) NOT NULL, external_link VARCHAR(255) NOT NULL, external_id BINARY(16) NOT NULL, external_status ENUM(\'CR\', \'GC\', \'UA\', \'RJ\', \'SA\', \'GA\', \'LN\', \'EX\', \'SU\') DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, INDEX IDX_D56C81C27E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (username VARCHAR(255) NOT NULL, last_logged_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_connection ADD CONSTRAINT FK_D56C81C27E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account_connection DROP FOREIGN KEY FK_D56C81C27E3C61F9');
        $this->addSql('DROP TABLE account_connection');
        $this->addSql('DROP TABLE user');
    }
}
