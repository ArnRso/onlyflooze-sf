<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250803103304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE csv_import_profile (id UUID NOT NULL, user_id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, delimiter VARCHAR(1) NOT NULL, encoding VARCHAR(20) NOT NULL, column_mapping JSON NOT NULL, date_format VARCHAR(50) NOT NULL, amount_type VARCHAR(20) NOT NULL, has_header BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_4862C8FEA76ED395 ON csv_import_profile (user_id)');
        $this->addSql('COMMENT ON COLUMN csv_import_profile.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN csv_import_profile.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN csv_import_profile.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN csv_import_profile.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE csv_import_session (id UUID NOT NULL, user_id UUID NOT NULL, profile_id UUID NOT NULL, filename VARCHAR(255) NOT NULL, total_rows INT NOT NULL, successful_imports INT NOT NULL, duplicates INT NOT NULL, errors INT NOT NULL, error_details JSON DEFAULT NULL, status VARCHAR(20) NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_1971B725A76ED395 ON csv_import_session (user_id)');
        $this->addSql('CREATE INDEX IDX_1971B725CCFA12B8 ON csv_import_session (profile_id)');
        $this->addSql('COMMENT ON COLUMN csv_import_session.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN csv_import_session.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN csv_import_session.profile_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN csv_import_session.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN csv_import_session.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE csv_import_profile ADD CONSTRAINT FK_4862C8FEA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE csv_import_session ADD CONSTRAINT FK_1971B725A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE csv_import_session ADD CONSTRAINT FK_1971B725CCFA12B8 FOREIGN KEY (profile_id) REFERENCES csv_import_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX unique_transaction_per_user ON transaction (user_id, transaction_date, amount, label)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE csv_import_profile DROP CONSTRAINT FK_4862C8FEA76ED395');
        $this->addSql('ALTER TABLE csv_import_session DROP CONSTRAINT FK_1971B725A76ED395');
        $this->addSql('ALTER TABLE csv_import_session DROP CONSTRAINT FK_1971B725CCFA12B8');
        $this->addSql('DROP TABLE csv_import_profile');
        $this->addSql('DROP TABLE csv_import_session');
        $this->addSql('DROP INDEX unique_transaction_per_user');
    }
}
