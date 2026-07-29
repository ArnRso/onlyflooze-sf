<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Éradication du schéma V1 : supprime toutes les tables de l'ancienne application
 * ainsi que les entrées de migrations obsolètes. Point de départ de la V2.
 */
final class Version20260729000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop V1 schema (all legacy tables) and forget V1 migrations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS transaction_tag CASCADE');
        $this->addSql('DROP TABLE IF EXISTS recurring_transaction CASCADE');
        $this->addSql('DROP TABLE IF EXISTS csv_import_session CASCADE');
        $this->addSql('DROP TABLE IF EXISTS csv_import_profile CASCADE');
        $this->addSql('DROP TABLE IF EXISTS transaction CASCADE');
        $this->addSql('DROP TABLE IF EXISTS tag CASCADE');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages CASCADE');
        $this->addSql('DROP TABLE IF EXISTS "user" CASCADE');
        $this->addSql("DELETE FROM doctrine_migration_versions WHERE version NOT LIKE 'DoctrineMigrations\\\\Version2026%'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Le schéma V1 ne peut pas être restauré.');
    }
}
