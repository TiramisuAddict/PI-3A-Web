<?php
namespace App\Tests\Service;

use App\Entity\Offre;
use App\Service\OffreManager;
use PHPUnit\Framework\TestCase;

class OffreManagerTest extends TestCase {
    public function testValidOffre(): void {
        $offre = new Offre();
        $offre->setTitrePoste('Développeur Symfony');
        $offre->setDateLimite(new \DateTime('+1 month'));

        $manager = new OffreManager();
        $this->assertTrue($manager->validate($offre));
    }

    public function testOffreSansTitre(): void {
        $this->expectException(\InvalidArgumentException::class);

        $offre = new Offre();
        $offre->setDateLimite(new \DateTime('+1 month'));

        $manager = new OffreManager();
        $manager->validate($offre);
    }

    public function testOffreAvecDateLimitePasse(): void {
        $this->expectException(\InvalidArgumentException::class);

        $offre = new Offre();
        $offre->setTitrePoste('Développeur Symfony');
        $offre->setDateLimite(new \DateTime('-1 day'));

        $manager = new OffreManager();
        $manager->validate($offre);
    }
}