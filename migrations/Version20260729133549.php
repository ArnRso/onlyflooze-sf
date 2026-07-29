<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729133549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add excluded transaction ids to recurrence (retroactive search refusals)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurrence ADD excluded_transaction_ids JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE recurrence ALTER excluded_transaction_ids DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recurrence DROP excluded_transaction_ids');
    }
}
