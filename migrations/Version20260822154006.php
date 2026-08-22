<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822154006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep track of the suggestion at review time (outcome, category, date) to measure precision over time';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_transaction ADD suggestion_outcome VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank_transaction ADD reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE bank_transaction ADD suggestion_at_review_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AEF169EEDB FOREIGN KEY (suggestion_at_review_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_50BCB3AEF169EEDB ON bank_transaction (suggestion_at_review_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AEF169EEDB');
        $this->addSql('DROP INDEX IDX_50BCB3AEF169EEDB');
        $this->addSql('ALTER TABLE bank_transaction DROP suggestion_outcome');
        $this->addSql('ALTER TABLE bank_transaction DROP reviewed_at');
        $this->addSql('ALTER TABLE bank_transaction DROP suggestion_at_review_id');
    }
}
