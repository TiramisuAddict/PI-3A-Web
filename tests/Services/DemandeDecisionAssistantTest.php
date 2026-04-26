<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Entity\Demande;
use App\Entity\Employe;
use App\Repository\DemandeRepository;
use App\Services\DemandeDecisionAssistant;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DemandeDecisionAssistantTest extends TestCase
{
    public function testAnalyzeAppliesRepeatTypePenaltyForSameEmployeeAndType(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->expects(self::once())
            ->method('countRecentSameTypeForEmploye')
            ->with(7, 'Conge', self::isInstanceOf(\DateTimeInterface::class), 7, 42)
            ->willReturn(2)
        ;

        $assistant = new DemandeDecisionAssistant(
            $repository,
            $this->createMock(LoggerInterface::class)
        );

        $employe = (new Employe())->setId_employe(7);

        $demande = (new Demande())
            ->setEmploye($employe)
            ->setTypeDemande('Conge')
            ->setTitre('Conge annuel')
            ->setDescription('Demande complete pour un conge annuel')
            ->setPriorite('NORMALE')
            ->setStatus('Nouvelle')
            ->setDateCreation(new \DateTimeImmutable('2026-04-24'));

        $demandeReflection = new \ReflectionProperty(Demande::class, 'id_demande');
        $demandeReflection->setAccessible(true);
        $demandeReflection->setValue($demande, 42);

        $details = [
            'typeConge' => 'Conge annuel',
            'dateDebut' => '2026-05-10',
            'dateFin' => '2026-05-14',
            'nombreJours' => '5',
            'motif' => 'Repos planifie apres une periode de forte charge de travail.',
        ];

        $fieldDefinitions = [
            ['key' => 'typeConge', 'label' => 'Type de conge', 'type' => 'select', 'required' => true],
            ['key' => 'dateDebut', 'label' => 'Date de debut', 'type' => 'date', 'required' => true],
            ['key' => 'dateFin', 'label' => 'Date de fin', 'type' => 'date', 'required' => true],
            ['key' => 'nombreJours', 'label' => 'Nombre de jours', 'type' => 'number', 'required' => true],
            ['key' => 'motif', 'label' => 'Motif', 'type' => 'textarea', 'required' => false],
        ];

        $result = $assistant->analyze($demande, $details, $fieldDefinitions);

        self::assertSame('En attente', $result['recommendedStatus']);
        self::assertSame(2, $result['repeatTypePenalty']['count']);
        self::assertLessThan(0.72, $result['confidence']);
        self::assertGreaterThan(0, $result['spamScore']);
        self::assertNotEmpty($result['reasons']);
    }

    public function testAnalyzeIgnoresNestedAiMetadataDetails(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('countRecentSameTypeForEmploye')
            ->willReturn(0)
        ;

        $assistant = new DemandeDecisionAssistant(
            $repository,
            $this->createMock(LoggerInterface::class)
        );

        $demande = (new Demande())
            ->setTypeDemande('Autre')
            ->setTitre('Reservation salle innovation')
            ->setDescription('Reservation d une salle pour organiser un atelier produit.')
            ->setPriorite('NORMALE')
            ->setStatus('Nouvelle')
            ->setDateCreation(new \DateTimeImmutable('2026-04-24'));

        $details = [
            'ai_salle' => 'Innovation',
            'ai_motif' => 'atelier produit',
            '_ai_field_plan' => [
                'add' => [
                    ['key' => 'ai_salle', 'label' => 'Salle'],
                ],
            ],
            'decisionAdvice' => [
                'recommendedStatus' => 'En cours',
            ],
        ];

        $result = $assistant->analyze($demande, $details, [
            ['key' => 'ai_salle', 'label' => 'Salle', 'type' => 'text', 'required' => true],
            ['key' => 'ai_motif', 'label' => 'Motif', 'type' => 'textarea', 'required' => true],
        ]);

        self::assertIsArray($result);
        self::assertSame([], $result['missingRequired']);
        self::assertArrayHasKey('mlSignals', $result);
    }
}
