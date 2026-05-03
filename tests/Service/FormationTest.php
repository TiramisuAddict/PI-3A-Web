<?php

namespace App\Tests\Service;
use App\Entity\Tache;
use App\Service\FormationManager;

use PHPUnit\Framework\TestCase;

class FormationTest extends TestCase
{
    /**
     * Test that a valid Tache with proper title and dates returns true.
     * Validates: non-empty title and date_limite >= date_deb.
     */
    public function testTacheValide(): void
    {
        $tache = new Tache();
        $tache->setTitre('Créer le module calendrier');
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-10'));

        $manager = new FormationManager();

        $this->assertTrue($manager->validate($tache));
    }

    /**
     * Test that a Tache with an empty title throws InvalidArgumentException.
     * Validates: Le titre est obligatoire (title is mandatory).
     */
    public function testTacheSansTitre(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tache = new Tache();
        $tache->setTitre('');
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-10'));

        $manager = new FormationManager();
        $manager->validate($tache);
    }

    /**
     * Test that a Tache with date_limite before date_deb throws InvalidArgumentException.
     * Validates: La date limite ne peut pas être antérieure à la date de début (end date cannot be before start date).
     */
    public function testTacheAvecDateLimiteAvantDateDebut(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tache = new Tache();
        $tache->setTitre('Créer le module calendrier');
        $tache->setDate_deb(new \DateTime('2026-06-10'));
        $tache->setDate_limite(new \DateTime('2026-06-01'));

        $manager = new FormationManager();
        $manager->validate($tache);
    }

    /**
     * Test that a Tache with only whitespace as title throws InvalidArgumentException.
     * Validates: Trimmed title must not be empty (trim and check for empty string).
     */
    public function testTacheAvecTitreEspacesSeuls(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tache = new Tache();
        $tache->setTitre('   ');
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-10'));

        $manager = new FormationManager();
        $manager->validate($tache);
    }

    /**
     * Test that a Tache with equal start and end dates is valid.
     * Validates: date_limite == date_deb is allowed and returns true.
     */
    public function testTacheAvecDateEgales(): void
    {
        $tache = new Tache();
        $tache->setTitre('Tâche avec dates identiques');
        $tache->setDate_deb(new \DateTime('2026-06-01'));
        $tache->setDate_limite(new \DateTime('2026-06-01'));

        $manager = new FormationManager();

        $this->assertTrue($manager->validate($tache));
    }

    /**
     * Test that a Tache with null dates is valid.
     * Validates: When both date_deb and date_limite are null, validation passes
     * (date comparison is skipped when dates are null).
     */
    public function testTacheAvecDatesNulles(): void
    {
        $tache = new Tache();
        $tache->setTitre('Tâche sans dates');
        // Both dates remain null (not set)

        $manager = new FormationManager();

        $this->assertTrue($manager->validate($tache));
    }

}
