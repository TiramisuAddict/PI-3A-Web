<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260403101553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inscription_formation (id_inscription INT AUTO_INCREMENT NOT NULL, id_formation INT NOT NULL, id_employe INT NOT NULL, statut VARCHAR(255) NOT NULL, raison LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_formation_employe (id_formation, id_employe), INDEX IDX_FORMATION (id_formation), PRIMARY KEY(id_inscription)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE inscription_formation ADD CONSTRAINT FK_FORMATION FOREIGN KEY (id_formation) REFERENCES formation (id_formation) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inscription_formation');
    }
}
