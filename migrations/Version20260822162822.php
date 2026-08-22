<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822162822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurrence learns the labels attached to it (fingerprints)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurrence ADD fingerprints JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE recurrence ALTER fingerprints DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurrence DROP fingerprints');
    }
}
