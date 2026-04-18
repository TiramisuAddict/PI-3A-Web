<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reply threading and edit tracking to feed comments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commentaire ADD edited_at DATETIME DEFAULT NULL, ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commentaire ADD CONSTRAINT FK_COMMENTAIRE_PARENT FOREIGN KEY (parent_id) REFERENCES commentaire (id_commentaire) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_COMMENTAIRE_PARENT ON commentaire (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commentaire DROP FOREIGN KEY FK_COMMENTAIRE_PARENT');
        $this->addSql('DROP INDEX IDX_COMMENTAIRE_PARENT ON commentaire');
        $this->addSql('ALTER TABLE commentaire DROP edited_at, DROP parent_id');
    }
}
