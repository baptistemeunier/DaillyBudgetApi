<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115145535 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE categorization_rule (pattern VARCHAR(100) NOT NULL, direction VARCHAR(255) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, category_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_RULE (owner_id, pattern, direction), INDEX IDX_F056C98A7E3C61F9 (owner_id), INDEX IDX_F056C98A12469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE categorization_rule ADD CONSTRAINT FK_F056C98A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categorization_rule ADD CONSTRAINT FK_F056C98A12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction ADD merchant_key VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_MERCHANT_KEY ON transaction (merchant_key)');
        $this->addSql('ALTER TABLE transaction_split ADD source VARCHAR(255) DEFAULT \'manual\' NOT NULL, ADD rule_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_split ADD CONSTRAINT FK_10C64A51744E0351 FOREIGN KEY (rule_id) REFERENCES categorization_rule (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_10C64A51744E0351 ON transaction_split (rule_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categorization_rule DROP FOREIGN KEY FK_F056C98A7E3C61F9');
        $this->addSql('ALTER TABLE categorization_rule DROP FOREIGN KEY FK_F056C98A12469DE2');
        $this->addSql('DROP TABLE categorization_rule');
        $this->addSql('DROP INDEX IDX_MERCHANT_KEY ON transaction');
        $this->addSql('ALTER TABLE transaction DROP merchant_key');
        $this->addSql('ALTER TABLE transaction_split DROP FOREIGN KEY FK_10C64A51744E0351');
        $this->addSql('DROP INDEX IDX_10C64A51744E0351 ON transaction_split');
        $this->addSql('ALTER TABLE transaction_split DROP source, DROP rule_id');
    }
}
