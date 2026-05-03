<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize demande foreign key naming and singularize demande detail table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demande DROP FOREIGN KEY fk_employe');
        $this->addSql('ALTER TABLE demande CHANGE id_employe employe_id INT NOT NULL');
        $this->addSql('ALTER TABLE demande DROP INDEX fk_employe, ADD INDEX idx_demande_employe_id (employe_id)');
        $this->addSql('ALTER TABLE demande ADD CONSTRAINT fk_demande_employe_id FOREIGN KEY (employe_id) REFERENCES employe (id_employe) ON DELETE CASCADE ON UPDATE CASCADE');

        $this->addSql('ALTER TABLE demande_details DROP FOREIGN KEY fk_demande');
        $this->addSql('ALTER TABLE demande_details CHANGE id_demande demande_id INT NOT NULL');
        $this->addSql('ALTER TABLE demande_details DROP INDEX fk_demande, ADD INDEX idx_demande_detail_demande_id (demande_id)');
        $this->addSql('RENAME TABLE demande_details TO demande_detail');
        $this->addSql('ALTER TABLE demande_detail ADD CONSTRAINT fk_demande_detail_demande_id FOREIGN KEY (demande_id) REFERENCES demande (id_demande) ON DELETE CASCADE ON UPDATE CASCADE');

        $this->addSql('ALTER TABLE historique_demande DROP FOREIGN KEY fk_his_demande');
        $this->addSql('ALTER TABLE historique_demande CHANGE id_demande demande_id INT NOT NULL');
        $this->addSql('ALTER TABLE historique_demande DROP INDEX fk_his_demande, ADD INDEX idx_historique_demande_demande_id (demande_id)');
        $this->addSql('ALTER TABLE historique_demande ADD CONSTRAINT fk_historique_demande_demande_id FOREIGN KEY (demande_id) REFERENCES demande (id_demande) ON DELETE CASCADE ON UPDATE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE historique_demande DROP FOREIGN KEY fk_historique_demande_demande_id');
        $this->addSql('ALTER TABLE historique_demande DROP INDEX idx_historique_demande_demande_id, ADD INDEX fk_his_demande (demande_id)');
        $this->addSql('ALTER TABLE historique_demande CHANGE demande_id id_demande INT NOT NULL');
        $this->addSql('ALTER TABLE historique_demande ADD CONSTRAINT fk_his_demande FOREIGN KEY (id_demande) REFERENCES demande (id_demande) ON DELETE CASCADE ON UPDATE CASCADE');

        $this->addSql('ALTER TABLE demande_detail DROP FOREIGN KEY fk_demande_detail_demande_id');
        $this->addSql('ALTER TABLE demande_detail DROP INDEX idx_demande_detail_demande_id, ADD INDEX fk_demande (demande_id)');
        $this->addSql('ALTER TABLE demande_detail CHANGE demande_id id_demande INT NOT NULL');
        $this->addSql('RENAME TABLE demande_detail TO demande_details');
        $this->addSql('ALTER TABLE demande_details ADD CONSTRAINT fk_demande FOREIGN KEY (id_demande) REFERENCES demande (id_demande) ON DELETE CASCADE ON UPDATE CASCADE');

        $this->addSql('ALTER TABLE demande DROP FOREIGN KEY fk_demande_employe_id');
        $this->addSql('ALTER TABLE demande DROP INDEX idx_demande_employe_id, ADD INDEX fk_employe (employe_id)');
        $this->addSql('ALTER TABLE demande CHANGE employe_id id_employe INT NOT NULL');
        $this->addSql('ALTER TABLE demande ADD CONSTRAINT fk_employe FOREIGN KEY (id_employe) REFERENCES employe (id_employe) ON DELETE CASCADE ON UPDATE CASCADE');
    }
}
