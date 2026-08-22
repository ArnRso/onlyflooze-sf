<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822160653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurrence tokens (recognition without a learned rule) and dismissed recurrence suggestions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recurrence_dismissal (id UUID NOT NULL, direction VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, head_token VARCHAR(150) NOT NULL, amount_cents INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE recurrence ADD tokens JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE recurrence ALTER tokens DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recurrence_dismissal');
        $this->addSql('ALTER TABLE recurrence DROP tokens');
    }
}
