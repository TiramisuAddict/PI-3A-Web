<?php

namespace App\Tests\Service;
use App\Entity\Tache;
use App\Service\TacheManager;

use PHPUnit\Framework\TestCase;

class TacheManagerTest extends TestCase
{
    public function testTacheValide(): void
    {
        $tache = new Tache();
        $tache->setTitre('Créer le module calendrier');
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-10'));

        $manager = new TacheManager();

        $this->assertTrue($manager->validate($tache));
    }

    public function testTacheSansTitre(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tache = new Tache();
        $tache->setTitre('');
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-10'));

        $manager = new TacheManager();
        $manager->validate($tache);
    }

    public function testTacheAvecDateLimiteAvantDateDebut(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tache = new Tache();
        $tache->setTitre('Créer le module calendrier');
        $tache->setDate_deb(new \DateTime('2026-06-10'));
        $tache->setDate_limite(new \DateTime('2026-06-01'));

        $manager = new TacheManager();
        $manager->validate($tache);
    }

    public function testTacheAvecTitreEspacesSeuls(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tache = new Tache();
        $tache->setTitre('   ');
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-10'));

        $manager = new TacheManager();
        $manager->validate($tache);
    }

    public function testTacheAvecDateEgales(): void
    {
        $tache = new Tache();
        $tache->setTitre('Tâche avec dates identiques');
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-01'));

        $manager = new TacheManager();

        $this->assertTrue($manager->validate($tache));
    }

    public function testTacheAvecTitreTresLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tache = new Tache();
        $tache->setTitre(str_repeat('a', 151));
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-10'));

        $manager = new TacheManager();
        $manager->validate($tache);
    }

}
