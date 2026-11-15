<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Low balance threshold per account (BALANCE_LOW notification)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD low_balance_threshold NUMERIC(15, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP low_balance_threshold');
    }
}
