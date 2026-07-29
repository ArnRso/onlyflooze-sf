<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed des postes de budget (dérivés des données réelles). Idempotent :
 * n'insère que si la table est vide, et l'utilisateur reste libre de
 * renommer/fusionner ensuite (les règles pointent des id).
 */
final class Version20260729130000 extends AbstractMigration
{
    private const array CATEGORIES = [
        '019fade7-5480-7731-8412-d7b6fa5743d5' => 'Logement',
        '019fade7-5480-77f9-8412-d7b6fb54867f' => 'Courses',
        '019fade7-5480-7841-8412-d7b6fb7c776d' => 'Voiture',
        '019fade7-5480-7861-8412-d7b6fc42f0d6' => 'Abonnements & télécom',
        '019fade7-5480-7879-8412-d7b6fc4e1a40' => 'Santé',
        '019fade7-5480-78a1-8412-d7b6fc71ba45' => 'Impôts',
        '019fade7-5480-78b9-8412-d7b6fcc32512' => 'Restos & sorties',
        '019fade7-5480-78dd-8412-d7b6fcc987b0' => 'Tabac',
        '019fade7-5480-78f1-8412-d7b6fd9ba6bf' => 'Shopping',
        '019fade7-5480-7909-8412-d7b6fe469310' => 'Animaux',
        '019fade7-5480-7921-8412-d7b6ff14640d' => 'Frais bancaires',
        '019fade7-5480-7935-8412-d7b6ffc1f298' => 'Revenus',
        '019fade7-5480-7949-8412-d7b700055e48' => 'Transferts internes',
        '019fade7-5480-7961-8412-d7b700904922' => 'PayPal à trier',
    ];

    public function getDescription(): string
    {
        return 'Seed initial categories (budget items derived from real data)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CATEGORIES as $id => $name) {
            $this->addSql(
                'INSERT INTO category (id, name, parent_id) SELECT :id, :name, NULL WHERE NOT EXISTS (SELECT 1 FROM category WHERE name = :name)',
                ['id' => $id, 'name' => $name],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM category WHERE id IN (:ids)', ['ids' => array_keys(self::CATEGORIES)], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
    }
}
