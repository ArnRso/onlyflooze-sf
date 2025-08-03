<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250803100637 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE recurring_transaction (id UUID NOT NULL, user_id UUID NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D3509AA6A76ED395 ON recurring_transaction (user_id)');
        $this->addSql('COMMENT ON COLUMN recurring_transaction.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recurring_transaction.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recurring_transaction.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN recurring_transaction.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE tag (id UUID NOT NULL, user_id UUID NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_389B783A76ED395 ON tag (user_id)');
        $this->addSql('COMMENT ON COLUMN tag.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN tag.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN tag.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN tag.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE transaction (id UUID NOT NULL, user_id UUID NOT NULL, recurring_transaction_id UUID DEFAULT NULL, label VARCHAR(255) NOT NULL, transaction_date DATE NOT NULL, amount NUMERIC(10, 2) NOT NULL, info TEXT DEFAULT NULL, budget_month VARCHAR(7) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_723705D1A76ED395 ON transaction (user_id)');
        $this->addSql('CREATE INDEX IDX_723705D15B71D755 ON transaction (recurring_transaction_id)');
        $this->addSql('COMMENT ON COLUMN transaction.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transaction.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transaction.recurring_transaction_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transaction.transaction_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN transaction.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN transaction.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE transaction_tag (transaction_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY(transaction_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_F8CD024A2FC0CB0F ON transaction_tag (transaction_id)');
        $this->addSql('CREATE INDEX IDX_F8CD024ABAD26311 ON transaction_tag (tag_id)');
        $this->addSql('COMMENT ON COLUMN transaction_tag.transaction_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN transaction_tag.tag_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON "user" (email)');
        $this->addSql('COMMENT ON COLUMN "user".id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE messenger_messages (id BIGSERIAL NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql('COMMENT ON COLUMN messenger_messages.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.available_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS $$
            BEGIN
                PERFORM pg_notify(\'messenger_messages\', NEW.queue_name::text);
                RETURN NEW;
            END;
        $$ LANGUAGE plpgsql;');
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages;');
        $this->addSql('CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON messenger_messages FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages();');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B783A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D15B71D755 FOREIGN KEY (recurring_transaction_id) REFERENCES recurring_transaction (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction_tag ADD CONSTRAINT FK_F8CD024A2FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transaction_tag ADD CONSTRAINT FK_F8CD024ABAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE recurring_transaction DROP CONSTRAINT FK_D3509AA6A76ED395');
        $this->addSql('ALTER TABLE tag DROP CONSTRAINT FK_389B783A76ED395');
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D1A76ED395');
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D15B71D755');
        $this->addSql('ALTER TABLE transaction_tag DROP CONSTRAINT FK_F8CD024A2FC0CB0F');
        $this->addSql('ALTER TABLE transaction_tag DROP CONSTRAINT FK_F8CD024ABAD26311');
        $this->addSql('DROP TABLE recurring_transaction');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE transaction');
        $this->addSql('DROP TABLE transaction_tag');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
