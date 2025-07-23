<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250722172627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Modification : ne supprime plus la colonne d
        $this->addSql('ALTER TABLE refresh_date ADD id INT AUTO_INCREMENT NOT NULL, ADD date DATETIME DEFAULT NULL, ADD PRIMARY KEY (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_date MODIFY id INT NOT NULL');
        $this->addSql('DROP INDEX `primary` ON refresh_date');
        // Ne supprime pas la colonne d
        // Ajoute la colonne d si nécessaire (à adapter selon ta base)
        // Tu peux la laisser en commentaire si elle existe déjà
        // $this->addSql('ALTER TABLE refresh_date ADD d DATE NOT NULL');
        $this->addSql('DROP id, DROP date');
    }
}
