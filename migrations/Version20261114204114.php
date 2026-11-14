<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114204114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account CHANGE bank_id bank_id VARCHAR(255) DEFAULT NULL, CHANGE synchronized_status synchronized_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction CHANGE external_id external_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_split ADD counterpart_transaction_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_split ADD CONSTRAINT FK_10C64A51E991DB2B FOREIGN KEY (counterpart_transaction_id) REFERENCES transaction (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_10C64A51E991DB2B ON transaction_split (counterpart_transaction_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account CHANGE bank_id bank_id VARCHAR(255) NOT NULL, CHANGE synchronized_status synchronized_status VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE transaction CHANGE external_id external_id VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE transaction_split DROP FOREIGN KEY FK_10C64A51E991DB2B');
        $this->addSql('DROP INDEX IDX_10C64A51E991DB2B ON transaction_split');
        $this->addSql('ALTER TABLE transaction_split DROP counterpart_transaction_id');
    }
}
