<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250803095221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE recurring_transaction (id SERIAL NOT NULL, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D3509AA6A76ED395 ON recurring_transaction (user_id)');
        $this->addSql('COMMENT ON COLUMN recurring_transaction.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN recurring_transaction.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction ADD recurring_transaction_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction ADD budget_month VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D15B71D755 FOREIGN KEY (recurring_transaction_id) REFERENCES recurring_transaction (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_723705D15B71D755 ON transaction (recurring_transaction_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D15B71D755');
        $this->addSql('ALTER TABLE recurring_transaction DROP CONSTRAINT FK_D3509AA6A76ED395');
        $this->addSql('DROP TABLE recurring_transaction');
        $this->addSql('DROP INDEX IDX_723705D15B71D755');
        $this->addSql('ALTER TABLE transaction DROP recurring_transaction_id');
        $this->addSql('ALTER TABLE transaction DROP budget_month');
    }
}
