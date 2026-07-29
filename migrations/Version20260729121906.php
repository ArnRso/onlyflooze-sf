<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260729121906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE TABLE bank_transaction (id UUID NOT NULL, operation_date DATE NOT NULL, value_date DATE NOT NULL, label TEXT NOT NULL, amount_cents INT NOT NULL, type VARCHAR(255) NOT NULL, tokens JSON NOT NULL, purchase_date DATE DEFAULT NULL, nature VARCHAR(255) NOT NULL, category_source VARCHAR(255) NOT NULL, amount_out_of_tolerance BOOLEAN NOT NULL, dedup_key VARCHAR(40) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, category_id UUID DEFAULT NULL, suggested_category_id UUID DEFAULT NULL, matched_rule_id UUID DEFAULT NULL, recurrence_id UUID DEFAULT NULL, import_batch_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_50BCB3AE12469DE2 ON bank_transaction (category_id)');
        $this->addSql('CREATE INDEX IDX_50BCB3AEDD17DE90 ON bank_transaction (suggested_category_id)');
        $this->addSql('CREATE INDEX IDX_50BCB3AEE4C2A719 ON bank_transaction (matched_rule_id)');
        $this->addSql('CREATE INDEX IDX_50BCB3AE2C414CE8 ON bank_transaction (recurrence_id)');
        $this->addSql('CREATE INDEX IDX_50BCB3AE5A310080 ON bank_transaction (import_batch_id)');
        $this->addSql('CREATE INDEX idx_transaction_dedup_key ON bank_transaction (dedup_key)');
        $this->addSql('CREATE INDEX idx_transaction_operation_date ON bank_transaction (operation_date)');
        $this->addSql('CREATE INDEX idx_transaction_category_source ON bank_transaction (category_source)');
        $this->addSql('CREATE TABLE bank_transaction_tag (transaction_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY (transaction_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_75530462FC0CB0F ON bank_transaction_tag (transaction_id)');
        $this->addSql('CREATE INDEX IDX_7553046BAD26311 ON bank_transaction_tag (tag_id)');
        $this->addSql('CREATE TABLE categorization_rule (id UUID NOT NULL, name VARCHAR(150) NOT NULL, direction VARCHAR(255) NOT NULL, tokens JSON NOT NULL, fingerprints JSON NOT NULL, amount_cents INT DEFAULT NULL, nature VARCHAR(255) DEFAULT NULL, confirmations INT NOT NULL, corrections INT NOT NULL, recurrence_opt_out BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, category_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F056C98A12469DE2 ON categorization_rule (category_id)');
        $this->addSql('CREATE TABLE category (id UUID NOT NULL, name VARCHAR(100) NOT NULL, parent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_64C19C1727ACA70 ON category (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_category_name_parent ON category (name, parent_id)');
        $this->addSql('CREATE TABLE import_batch (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, new_count INT NOT NULL, duplicate_count INT NOT NULL, auto_categorized_count INT NOT NULL, suggested_count INT NOT NULL, to_review_count INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE recurrence (id UUID NOT NULL, name VARCHAR(150) NOT NULL, direction VARCHAR(255) NOT NULL, expected_day_of_month INT NOT NULL, expected_amount_cents INT NOT NULL, tolerance_pct INT NOT NULL, day_window INT NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, category_id UUID DEFAULT NULL, rule_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1FB7F22112469DE2 ON recurrence (category_id)');
        $this->addSql('CREATE INDEX IDX_1FB7F221744E0351 ON recurrence (rule_id)');
        $this->addSql('CREATE TABLE tag (id UUID NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_389B7835E237E06 ON tag (name)');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AE12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AEDD17DE90 FOREIGN KEY (suggested_category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AEE4C2A719 FOREIGN KEY (matched_rule_id) REFERENCES categorization_rule (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AE2C414CE8 FOREIGN KEY (recurrence_id) REFERENCES recurrence (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AE5A310080 FOREIGN KEY (import_batch_id) REFERENCES import_batch (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction_tag ADD CONSTRAINT FK_75530462FC0CB0F FOREIGN KEY (transaction_id) REFERENCES bank_transaction (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_transaction_tag ADD CONSTRAINT FK_7553046BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categorization_rule ADD CONSTRAINT FK_F056C98A12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurrence ADD CONSTRAINT FK_1FB7F22112469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurrence ADD CONSTRAINT FK_1FB7F221744E0351 FOREIGN KEY (rule_id) REFERENCES categorization_rule (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AE12469DE2');
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AEDD17DE90');
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AEE4C2A719');
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AE2C414CE8');
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AE5A310080');
        $this->addSql('ALTER TABLE bank_transaction_tag DROP CONSTRAINT FK_75530462FC0CB0F');
        $this->addSql('ALTER TABLE bank_transaction_tag DROP CONSTRAINT FK_7553046BAD26311');
        $this->addSql('ALTER TABLE categorization_rule DROP CONSTRAINT FK_F056C98A12469DE2');
        $this->addSql('ALTER TABLE category DROP CONSTRAINT FK_64C19C1727ACA70');
        $this->addSql('ALTER TABLE recurrence DROP CONSTRAINT FK_1FB7F22112469DE2');
        $this->addSql('ALTER TABLE recurrence DROP CONSTRAINT FK_1FB7F221744E0351');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE bank_transaction');
        $this->addSql('DROP TABLE bank_transaction_tag');
        $this->addSql('DROP TABLE categorization_rule');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE import_batch');
        $this->addSql('DROP TABLE recurrence');
        $this->addSql('DROP TABLE tag');
    }
}
