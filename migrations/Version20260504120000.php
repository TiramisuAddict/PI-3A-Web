<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve comment replies when parent comments are deleted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commentaire DROP FOREIGN KEY FK_COMMENTAIRE_PARENT');
        $this->addSql('ALTER TABLE commentaire ADD CONSTRAINT FK_COMMENTAIRE_PARENT FOREIGN KEY (parent_id) REFERENCES commentaire (id_commentaire) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commentaire DROP FOREIGN KEY FK_COMMENTAIRE_PARENT');
        $this->addSql('ALTER TABLE commentaire ADD CONSTRAINT FK_COMMENTAIRE_PARENT FOREIGN KEY (parent_id) REFERENCES commentaire (id_commentaire) ON DELETE CASCADE');
    }
}
