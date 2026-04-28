<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Repository\DemandeRepository;
use App\Services\DemandeAiAssistant;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DemandeAiAssistantTest extends TestCase
{
    public function testExtractSuggestedDetailsForTypeFillsDateSouhaiteeAutreFromFreePrompt(): void
    {
        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(DemandeRepository::class),
            'test-key',
            'test-model'
        );

        $fields = [
            [
                'key' => 'dateSouhaiteeAutre',
                'label' => 'Date souhaitee',
                'type' => 'date',
                'required' => false,
            ],
            [
                'key' => 'descriptionBesoin',
                'label' => 'Description detaillee du besoin',
                'type' => 'textarea',
                'required' => true,
            ],
        ];

        $prompt = 'je souhaite demande un transport en bus pour une formation professionnelle en ui/ix a hammam-lif qui debute le 12 decembre.';

        $details = $assistant->extractSuggestedDetailsForType($prompt, 'Autre', $fields);

        self::assertArrayHasKey('dateSouhaiteeAutre', $details);
        self::assertMatchesRegularExpression('/^\d{4}-12-12$/', (string) $details['dateSouhaiteeAutre']);
    }

    public function testExtractSuggestedDetailsForTypeDoesNotLeakMonthIntoLocation(): void
    {
        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(DemandeRepository::class),
            'test-key',
            'test-model'
        );

        $fields = [
            [
                'key' => 'lieuFormation',
                'label' => 'Lieu de formation',
                'type' => 'text',
                'required' => false,
            ],
        ];

        $prompt = 'je souhaite demande un transport en bus pour une formation professionnelle en ui/ix a hammam-lif qui debute le 12 decembre.';
        $details = $assistant->extractSuggestedDetailsForType($prompt, 'Autre', $fields);

        self::assertSame('hammam-lif', strtolower((string) ($details['lieuFormation'] ?? '')));
        self::assertNotSame('ecembre', strtolower((string) ($details['lieuFormation'] ?? '')));
    }

    public function testAutoCorrectTextRejectsReformulationStyleRewrite(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response
            ->expects(self::once())
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'matches' => [
                    [
                        'offset' => 0,
                        'length' => 24,
                        'replacements' => [
                            ['value' => 'Je souhaite soumettre une demande de parking pres de l entree pour une duree temporaire.'],
                        ],
                    ],
                ],
            ])
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($response)
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $this->createMock(DemandeRepository::class),
            '',
            ''
        );

        $corrected = $assistant->autoCorrectText('parking pres de l entree');

        self::assertSame('parking pres de l entree', strtolower($corrected));
        self::assertStringNotContainsString('soumettre une demande', strtolower($corrected));
    }

    public function testGenerateAutreSuggestionsReusesConfirmedFieldPlanWithoutDefaultAutreFields(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'attestation de salaire pour banque',
                    'confirmed' => true,
                    'general' => [
                        'titre' => 'Attestation de salaire',
                        'description' => 'Demande d attestation de salaire pour un dossier bancaire.',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_attestation' => 'Attestation de salaire',
                        'ai_organisme_destinataire' => 'banque',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_attestation',
                                'label' => 'Type d attestation',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_organisme_destinataire',
                                'label' => 'Organisme destinataire',
                                'type' => 'text',
                                'required' => false,
                            ],
                        ],
                        'remove' => [],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $repository,
            '',
            ''
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'attestation de salaire pour banque',
            ],
            [],
            [
                [
                    'key' => 'besoinPersonnalise',
                    'label' => 'Nom de votre demande',
                    'type' => 'text',
                    'required' => true,
                ],
                [
                    'key' => 'descriptionBesoin',
                    'label' => 'Description detaillee du besoin',
                    'type' => 'textarea',
                    'required' => true,
                ],
                [
                    'key' => 'dateSouhaiteeAutre',
                    'label' => 'Date souhaitee',
                    'type' => 'date',
                    'required' => false,
                ],
            ]
        );

        self::assertSame('local-ml:demande_adaptive_model.py', $result['model']);
        self::assertTrue($result['dynamicFieldPlan']['replaceBase']);
        self::assertNotEmpty($result['suggestedDetails']);
        self::assertArrayNotHasKey('besoinPersonnalise', $result['suggestedDetails']);
        self::assertArrayNotHasKey('descriptionBesoin', $result['suggestedDetails']);
        self::assertArrayNotHasKey('dateSouhaiteeAutre', $result['suggestedDetails']);
    }

    public function testGenerateAutreSuggestionsKeepsOnlyLearnedFieldsSupportedByCurrentPrompt(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'reservation salle focus pour atelier produit le 3 mai',
                    'confirmed' => true,
                    'createdAt' => '2026-04-26T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Reservation salle focus',
                        'description' => 'Reservation salle focus pour atelier produit le 3 mai.',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_salle' => 'Focus',
                        'ai_motif' => 'atelier produit',
                        'ai_date_souhaitee' => '2026-05-03',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_salle',
                                'label' => 'Salle',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_motif',
                                'label' => 'Motif',
                                'type' => 'textarea',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_date_souhaitee',
                                'label' => 'Date souhaitee',
                                'type' => 'date',
                                'required' => false,
                            ],
                        ],
                        'remove' => [],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $repository,
            '',
            ''
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'reservation salle Focus pour atelier produit',
            ],
            [],
            []
        );

        self::assertSame('local-ml:demande_adaptive_model.py', $result['model']);
        self::assertSame('Focus', $result['suggestedDetails']['ai_salle'] ?? null);
        self::assertStringContainsString('atelier produit', strtolower((string) ($result['suggestedDetails']['ai_motif'] ?? '')));
        self::assertArrayNotHasKey('ai_date_souhaitee', $result['suggestedDetails']);
        self::assertFalse($result['skipConfirmationRestriction']);
        self::assertGreaterThanOrEqual(2, count($result['dynamicFieldPlan']['add']));
    }

    public function testGenerateAutreSuggestionsFallsBackToFreshExtractionForChangedPrompt(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([]);

        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $repository,
            '',
            ''
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'Reservation salle innovation pour atelier produit a 16h pour 45 minutes',
            ],
            [],
            []
        );

        self::assertSame('local-ml:demande_adaptive_model.py', $result['model']);
        self::assertArrayNotHasKey('ai_salle', $result['suggestedDetails']);
        self::assertArrayNotHasKey('ai_motif', $result['suggestedDetails']);
        self::assertNotEmpty($result['suggestedDetails']);
    }

    public function testGenerateAutreSuggestionsPrioritizesManualSchemaButUsesCurrentPromptValues(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je veux une souris professionnel pour travail sur design',
                    'confirmed' => false,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Demande de materiel souris',
                        'description' => 'Je veux une souris professionnel pour travail sur design',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_materiel_concerne' => 'souris',
                        'ai_justification_metier' => 'travail sur design',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_materiel_concerne',
                                'label' => 'Materiel concerne',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_justification_metier',
                                'label' => 'Justification',
                                'type' => 'textarea',
                                'required' => false,
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $repository,
            '',
            ''
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'je veux un casque pour travail sur design',
            ],
            [],
            []
        );

        self::assertSame('casque', $result['suggestedDetails']['ai_materiel_concerne'] ?? null);
        self::assertNotSame('souris', $result['suggestedDetails']['ai_materiel_concerne'] ?? null);
        self::assertStringContainsString('travail sur design', strtolower((string) ($result['suggestedDetails']['ai_justification_metier'] ?? '')));
    }

    public function testGenerateAutreSuggestionsFillsArbitraryLearnedFieldsFromPrompt(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'besoin de remboursement hotel 120 tnd pour mission client a Tunis',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Remboursement hotel',
                        'description' => 'Besoin de remboursement hotel 120 tnd pour mission client a Tunis',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_frais' => 'hotel',
                        'ai_montant' => '120',
                        'ai_motif' => 'mission client a Tunis',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_frais',
                                'label' => 'Type frais',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_montant',
                                'label' => 'Montant',
                                'type' => 'number',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_motif',
                                'label' => 'Motif',
                                'type' => 'textarea',
                                'required' => true,
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $repository,
            '',
            ''
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'besoin de remboursement transport 80 tnd pour mission client a Sousse',
            ],
            [],
            []
        );

        self::assertSame('transport', $result['suggestedDetails']['ai_type_frais'] ?? null);
        self::assertSame('80', $result['suggestedDetails']['ai_montant'] ?? null);
        self::assertStringContainsString('mission client a sousse', strtolower((string) ($result['suggestedDetails']['ai_motif'] ?? '')));
        self::assertNotSame('hotel', $result['suggestedDetails']['ai_type_frais'] ?? null);
        self::assertNotSame('120', $result['suggestedDetails']['ai_montant'] ?? null);
    }

    public function testGenerateAutreSuggestionsAdaptsTimeAndPeriodFields(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'Je veux un shift de jour de 8h a 17h uniquement pendant cette semaine',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Shift de jour',
                        'description' => 'Je veux un shift de jour de 8h a 17h uniquement pendant cette semaine',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_shift' => 'Jour',
                        'ai_horaire_debut' => '8h',
                        'ai_horaire_fin' => '17h',
                        'ai_periode' => 'Semaine en cours',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_shift',
                                'label' => 'Type shift',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_horaire_debut',
                                'label' => 'Horaire debut',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_horaire_fin',
                                'label' => 'Horaire fin',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_periode',
                                'label' => 'Periode',
                                'type' => 'text',
                                'required' => true,
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $repository,
            '',
            ''
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'Je veux un shift de nuit de 20h a 7h uniquement pendant la semaine prochaine',
            ],
            [],
            []
        );

        self::assertSame('Nuit', $result['suggestedDetails']['ai_type_shift'] ?? null);
        self::assertSame('20h', $result['suggestedDetails']['ai_horaire_debut'] ?? null);
        self::assertSame('7h', $result['suggestedDetails']['ai_horaire_fin'] ?? null);
        self::assertSame('Semaine prochaine', $result['suggestedDetails']['ai_periode'] ?? null);
    }

    public function testGenerateAutreSuggestionsDoesNotUseRequestIntroAsLearnedValue(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'Je veux un shift de jour de 8h a 17h uniquement pendant cette semaine',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Shift de jour',
                        'description' => 'Je veux un shift de jour de 8h a 17h uniquement pendant cette semaine',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_demande' => 'Shift de jour',
                        'ai_shift_souhaite' => 'Jour',
                        'ai_horaire_souhaite' => '8h-17h',
                        'ai_periode_concernee' => 'Semaine en cours',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_demande',
                                'label' => 'Type de demande',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_shift_souhaite',
                                'label' => 'Shift souhaite',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_horaire_souhaite',
                                'label' => 'Horaire souhaite',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_periode_concernee',
                                'label' => 'Periode concernee',
                                'type' => 'text',
                                'required' => true,
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $repository,
            '',
            ''
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'Je veux un shift de nuit (22h-7h) uniquement pendant la semaine prochaine.',
            ],
            [],
            []
        );

        self::assertSame('Shift de nuit', $result['suggestedDetails']['ai_type_demande'] ?? null);
        self::assertFalse(str_starts_with((string) ($result['suggestedDetails']['ai_type_demande'] ?? ''), 'Je veux'));
        self::assertSame('Nuit', $result['suggestedDetails']['ai_shift_souhaite'] ?? null);
        self::assertSame('22h-7h', $result['suggestedDetails']['ai_horaire_souhaite'] ?? null);
        self::assertSame('Semaine prochaine', $result['suggestedDetails']['ai_periode_concernee'] ?? null);
    }
}
