<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification settings (one per user)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_settings (push JSON NOT NULL, large_transaction_threshold NUMERIC(15, 2) NOT NULL, hide_amounts TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_OWNER (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification_settings ADD CONSTRAINT FK_B05598607E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_settings DROP FOREIGN KEY FK_B05598607E3C61F9');
        $this->addSql('DROP TABLE notification_settings');
    }
}
