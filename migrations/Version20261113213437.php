<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113213437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction_split (amount NUMERIC(15, 2) NOT NULL, id BINARY(16) NOT NULL, transaction_id BINARY(16) NOT NULL, category_id BINARY(16) DEFAULT NULL, INDEX IDX_10C64A512FC0CB0F (transaction_id), INDEX IDX_10C64A5112469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE transaction_split ADD CONSTRAINT FK_10C64A512FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction_split ADD CONSTRAINT FK_10C64A5112469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY `FK_723705D112469DE2`');
        $this->addSql('DROP INDEX IDX_723705D112469DE2 ON transaction');
        $this->addSql('ALTER TABLE transaction DROP category_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_split DROP FOREIGN KEY FK_10C64A512FC0CB0F');
        $this->addSql('ALTER TABLE transaction_split DROP FOREIGN KEY FK_10C64A5112469DE2');
        $this->addSql('DROP TABLE transaction_split');
        $this->addSql('ALTER TABLE transaction ADD category_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT `FK_723705D112469DE2` FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_723705D112469DE2 ON transaction (category_id)');
    }
}
