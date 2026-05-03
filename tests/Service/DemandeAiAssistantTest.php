<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Repository\DemandeRepository;
use App\Service\DemandeAiAssistant;
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

    public function testGenerateDescriptionFromTitleFallsBackToDeterministicTextWhenNoAiModelIsConfigured(): void
    {
        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(DemandeRepository::class),
            '',
            ''
        );

        $result = $assistant->generateDescriptionFromTitle('Demande de badge acces');

        self::assertSame('deterministic:description-from-title', $result['model']);
        self::assertStringStartsWith('Bonjour, je souhaite demander badge acces.', $result['description']);
        self::assertStringContainsString('cadre de mon travail', $result['description']);
    }

    public function testGenerateClassificationSuggestionRejectsEmptyText(): void
    {
        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(DemandeRepository::class),
            'test-key',
            'test-model'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ajoutez une description avant de lancer la suggestion intelligente.');

        $assistant->generateClassificationSuggestion('', [
            'Ressources Humaines' => ['Conge'],
        ], ['NORMALE']);
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

        self::assertSame('local-ml:demande/demande_adaptive_model.py', $result['model']);
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

        self::assertSame('local-ml:demande/demande_adaptive_model.py', $result['model']);
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

        self::assertSame('local-ml:demande/demande_adaptive_model.py', $result['model']);
        self::assertArrayNotHasKey('ai_salle', $result['suggestedDetails']);
        self::assertArrayNotHasKey('ai_motif', $result['suggestedDetails']);
        self::assertNotEmpty($result['suggestedDetails']);
    }

    public function testGenerateAutreSuggestionsUsesLlmDirectlyWhenNoLearningSamplesExist(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->expects(self::once())
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([])
        ;

        $response = $this->createMock(ResponseInterface::class);
        $response
            ->expects(self::never())
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'correctedText' => 'Je veux un badge visiteur pour demain',
                                'general' => [
                                    'titre' => 'Badge visiteur',
                                    'description' => 'Je veux un badge visiteur pour demain',
                                    'priorite' => 'NORMALE',
                                    'categorie' => 'Autre',
                                    'typeDemande' => 'Autre',
                                ],
                                'details' => [
                                    'ai_objet_demande' => 'badge visiteur',
                                ],
                                'custom_fields' => [
                                    [
                                        'key' => 'ai_objet_demande',
                                        'label' => 'Objet de la demande',
                                        'type' => 'text',
                                        'required' => true,
                                        'value' => 'badge visiteur',
                                    ],
                                ],
                                'remove_fields' => ['ALL'],
                                'replace_base' => true,
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ])
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request')
            ->with(
                'POST',
                'https://router.huggingface.co/v1/chat/completions',
                self::anything()
            )
            ->willReturn($response)
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model'
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'Je veux un badge visiteur pour demain',
            ],
            [],
            []
        );

        self::assertSame('local-ml:demande/demande_adaptive_model.py', $result['model']);
        self::assertNotEmpty($result['suggestedDetails']);
        self::assertSame('Moyenne', $result['dynamicFieldConfidence']['label'] ?? null);
    }

    public function testGenerateAutreSuggestionsReusesRelatedDatabaseSchemaWithoutCallingLlm(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'Je veux un ecran 32 pouces',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Materiel ecran',
                        'description' => 'Je veux un ecran 32 pouces',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_materiel_concerne' => 'ecran',
                        'ai_specification' => '32 pouces',
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
                                'key' => 'ai_specification',
                                'label' => 'Specification',
                                'type' => 'text',
                                'required' => false,
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request')
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model'
        );

        $keyboardResult = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'Je veux un clavier mecanique',
            ],
            [],
            []
        );

        $headsetResult = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'Je veux un micro casque gaming',
            ],
            [],
            []
        );

        self::assertSame('local-ml:demande/demande_adaptive_model.py', $keyboardResult['model']);
        self::assertSame('clavier', $keyboardResult['suggestedDetails']['ai_materiel_concerne'] ?? null);
        self::assertSame('mecanique', $keyboardResult['suggestedDetails']['ai_specification'] ?? null);
        self::assertSame('micro casque', $headsetResult['suggestedDetails']['ai_materiel_concerne'] ?? null);
        self::assertSame('gaming', $headsetResult['suggestedDetails']['ai_specification'] ?? null);
    }

    public function testGenerateAutreSuggestionsUsesLlmWhenDatabaseSamplesDoNotMatch(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'besoin de remboursement hotel 120 tnd pour mission client',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Remboursement hotel',
                        'description' => 'Besoin de remboursement hotel 120 tnd pour mission client',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_frais' => 'hotel',
                        'ai_montant' => '120',
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
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $response = $this->createMock(ResponseInterface::class);
        $response
            ->expects(self::never())
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'correctedText' => 'Je veux un badge visiteur pour demain',
                                'general' => [
                                    'titre' => 'Badge visiteur',
                                    'description' => 'Je veux un badge visiteur pour demain',
                                    'priorite' => 'NORMALE',
                                    'categorie' => 'Autre',
                                    'typeDemande' => 'Autre',
                                ],
                                'details' => [
                                    'ai_objet_demande' => 'badge visiteur',
                                ],
                                'custom_fields' => [
                                    [
                                        'key' => 'ai_objet_demande',
                                        'label' => 'Objet de la demande',
                                        'type' => 'text',
                                        'required' => true,
                                        'value' => 'badge visiteur',
                                    ],
                                ],
                                'remove_fields' => ['ALL'],
                                'replace_base' => true,
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ])
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request')
            ->with(
                'POST',
                'https://router.huggingface.co/v1/chat/completions',
                self::anything()
            )
            ->willReturn($response)
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model'
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'Je veux un badge visiteur pour demain',
            ],
            [],
            []
        );

        self::assertSame('local-ml:demande/demande_adaptive_model.py', $result['model']);
        self::assertNotEmpty($result['suggestedDetails']);
    }

    public function testGenerateAutreSuggestionsUsesLlmWhenDatabaseMatchIsWeak(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je veux un ecran 32 pouces',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Materiel ecran',
                        'description' => 'je veux un ecran 32 pouces',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_materiel_concerne' => 'ecran',
                        'ai_specification' => '32 pouces',
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
                                'key' => 'ai_specification',
                                'label' => 'Specification',
                                'type' => 'text',
                                'required' => false,
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $response = $this->createMock(ResponseInterface::class);
        $response
            ->expects(self::never())
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'correctedText' => 'Demande d un transport par taxi pour une formation externe de HTML',
                                'general' => [
                                    'titre' => 'Transport pour formation HTML',
                                    'description' => 'Demande de transport par taxi pour participer a une formation externe de HTML.',
                                    'priorite' => 'NORMALE',
                                ],
                                'details' => [
                                    'ai_type_transport' => 'taxi',
                                    'ai_objet_deplacement' => 'formation externe de HTML',
                                ],
                                'custom_fields' => [
                                    [
                                        'key' => 'ai_type_transport',
                                        'label' => 'Type de transport',
                                        'type' => 'text',
                                        'required' => true,
                                        'value' => 'taxi',
                                    ],
                                    [
                                        'key' => 'ai_objet_deplacement',
                                        'label' => 'Objet du deplacement',
                                        'type' => 'text',
                                        'required' => true,
                                        'value' => 'formation externe de HTML',
                                    ],
                                ],
                                'remove_fields' => ['ALL'],
                                'replace_base' => true,
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ])
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request')
            ->with(
                'POST',
                'https://router.huggingface.co/v1/chat/completions',
                self::anything()
            )
            ->willReturn($response)
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model'
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'demande d un transport par taxi pour une formation externe de html',
            ],
            [],
            []
        );

        self::assertSame('local-ml:demande/demande_adaptive_model.py', $result['model']);
        self::assertSame('Taxi', $result['suggestedDetails']['ai_type_transport'] ?? null);
        self::assertSame('Html', $result['suggestedDetails']['ai_nom_formation'] ?? null);
        self::assertArrayNotHasKey('ai_materiel_concerne', $result['suggestedDetails']);
    }

    public function testGenerateAutreSuggestionsDoesNotBlockLlmForEmptyManualPlan(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([])
        ;

        $response = $this->createMock(ResponseInterface::class);
        $response
            ->expects(self::never())
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'correctedText' => 'Je veux un badge visiteur',
                                'general' => [
                                    'titre' => 'Badge visiteur',
                                    'description' => 'Je veux un badge visiteur',
                                    'priorite' => 'NORMALE',
                                    'categorie' => 'Autre',
                                    'typeDemande' => 'Autre',
                                ],
                                'details' => [
                                    'ai_objet_demande' => 'badge visiteur',
                                ],
                                'custom_fields' => [
                                    [
                                        'key' => 'ai_objet_demande',
                                        'label' => 'Objet de la demande',
                                        'type' => 'text',
                                        'required' => true,
                                        'value' => 'badge visiteur',
                                    ],
                                ],
                                'remove_fields' => ['ALL'],
                                'replace_base' => true,
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ])
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request')
            ->willReturn($response)
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model'
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'Je veux un badge visiteur',
                'manualFieldMode' => true,
                'manualFieldPlan' => ['add' => [], 'remove' => [], 'replaceBase' => true],
            ],
            [],
            []
        );

        self::assertSame('local-ml:demande/demande_adaptive_model.py', $result['model']);
        self::assertNotEmpty($result['suggestedDetails']);
    }

    public function testGenerateAutreSuggestionsReadsRouteWithoutDuplicatingGenericTransportFields(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je veux une demande de transport pour une formation java de Tunis vers Rades le 12 mai',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Transport formation Java',
                        'description' => 'je veux une demande de transport pour une formation java de Tunis vers Rades le 12 mai',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_lieu_depart_actuel' => 'Tunis',
                        'ai_lieu_souhaite' => 'Rades',
                        'ai_nom_formation' => 'Java',
                        'ai_custom_objet' => 'Transport formation',
                        'ai_type_transport' => 'Bus',
                        'ai_date_souhaitee_metier' => '2026-05-12',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_lieu_depart_actuel',
                                'label' => 'Lieu de depart actuel',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_lieu_souhaite',
                                'label' => 'Lieu souhaite',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_nom_formation',
                                'label' => 'Nom de la formation',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_custom_objet',
                                'label' => 'Custom Objet',
                                'type' => 'text',
                                'required' => false,
                            ],
                            [
                                'key' => 'ai_type_transport',
                                'label' => 'Type transport',
                                'type' => 'text',
                                'required' => false,
                            ],
                            [
                                'key' => 'ai_date_souhaitee_metier',
                                'label' => 'Date souhaitee metier',
                                'type' => 'date',
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
                'aiDescriptionPrompt' => 'je veux une demande de transport pour une formation javafx de hammamlif vers Sousse le 21 mai',
            ],
            [],
            [
                [
                    'key' => 'dateSouhaiteeAutre',
                    'label' => 'Date souhaitee',
                    'type' => 'date',
                    'required' => false,
                ],
            ]
        );

        self::assertSame('hammamlif', strtolower((string) ($result['suggestedDetails']['ai_lieu_depart_actuel'] ?? '')));
        self::assertSame('sousse', strtolower((string) ($result['suggestedDetails']['ai_lieu_souhaite'] ?? '')));
        self::assertSame('javafx', strtolower((string) ($result['suggestedDetails']['ai_nom_formation'] ?? '')));
        self::assertArrayNotHasKey('ai_custom_objet', $result['suggestedDetails']);
        self::assertArrayNotHasKey('ai_type_transport', $result['suggestedDetails']);

        $fieldKeys = array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'] ?? []);
        self::assertNotContains('ai_custom_objet', $fieldKeys);
        self::assertNotContains('ai_type_transport', $fieldKeys);
    }

    public function testGenerateAutreSuggestionsKeepsTrainingNameSeparateFromTrainingType(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je veux une demande de transport pour une formation interne de java de Tunis vers Rades le 12 mai',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Transport formation Java',
                        'description' => 'je veux une demande de transport pour une formation interne de java de Tunis vers Rades le 12 mai',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_lieu_depart_actuel' => 'Tunis',
                        'ai_lieu_souhaite' => 'Rades',
                        'ai_nom_formation' => 'Java',
                        'ai_type_formation' => 'Formation interne',
                        'ai_date_souhaitee_metier' => '2026-05-12',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_lieu_depart_actuel',
                                'label' => 'Lieu de depart actuel',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_lieu_souhaite',
                                'label' => 'Lieu souhaite',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_nom_formation',
                                'label' => 'Nom de la formation',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_type_formation',
                                'label' => 'Type de formation',
                                'type' => 'select',
                                'required' => true,
                                'options' => ['Formation interne', 'Formation externe', 'Certification', 'Autre'],
                            ],
                            [
                                'key' => 'ai_date_souhaitee_metier',
                                'label' => 'Date souhaitee metier',
                                'type' => 'date',
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

        $prompts = [
            'je veux une demande de transport pour une formation externe de javafx de hammamlif vers Sousse le 21 mai',
            'je veux une demande de transport pour une formation de javafx cette formation est une formation externe de hammamlif vers Sousse le 21 mai',
        ];

        foreach ($prompts as $prompt) {
            $result = $assistant->generateAutreSuggestions(
                [
                    'typeDemande' => 'Autre',
                    'aiDescriptionPrompt' => $prompt,
                ],
                [],
                []
            );

            self::assertSame('hammamlif', strtolower((string) ($result['suggestedDetails']['ai_lieu_depart_actuel'] ?? '')), $prompt);
            self::assertSame('sousse', strtolower((string) ($result['suggestedDetails']['ai_lieu_souhaite'] ?? '')), $prompt);
            self::assertSame('javafx', strtolower((string) ($result['suggestedDetails']['ai_nom_formation'] ?? '')), $prompt);
            self::assertSame('formation externe', strtolower((string) ($result['suggestedDetails']['ai_type_formation'] ?? '')), $prompt);
        }
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
        $expectedNextWeek = (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');

        self::assertSame('Nuit', $result['suggestedDetails']['ai_type_shift'] ?? null);
        self::assertSame('20h', $result['suggestedDetails']['ai_horaire_debut'] ?? null);
        self::assertSame('7h', $result['suggestedDetails']['ai_horaire_fin'] ?? null);
        self::assertSame($expectedNextWeek, $result['suggestedDetails']['ai_periode'] ?? null);
    }

    public function testTargetOnlySchedulePromptDropsEmptyCurrentScheduleFieldFromLearnedSchema(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je souhaite un horaire reduit 8h-17h pendant 2 semaines',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Horaire reduit',
                        'description' => 'je souhaite un horaire reduit 8h-17h pendant 2 semaines',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_horaire_souhaite' => '8h-17h',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_horaire_actuel',
                                'label' => 'Horaire actuel',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_horaire_souhaite',
                                'label' => 'Horaire souhaite',
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
                'aiDescriptionPrompt' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines',
            ],
            [],
            []
        );

        self::assertSame('9h-14h', $result['suggestedDetails']['ai_horaire_souhaite'] ?? null);
        self::assertArrayNotHasKey('ai_horaire_actuel', $result['suggestedDetails']);
        self::assertNotContains(
            'ai_horaire_actuel',
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'])
        );
    }

    public function testLearnedManualCustomFieldSurvivesMinorPromptWordingChange(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines suite convalescence',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-05-01T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Horaire reduit',
                        'description' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines suite convalescence',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_besoinpersonnalise' => 'horaire reduit',
                        'ai_horriaireactuel' => '9h-14h',
                        'ai_periode_concernee' => '2 semaines',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_besoinpersonnalise',
                                'label' => 'besoinPersonnalise',
                                'type' => 'text',
                                'required' => false,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_horriaireactuel',
                                'label' => 'HorriaireActuel',
                                'type' => 'text',
                                'required' => false,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_periode_concernee',
                                'label' => 'Periode concernee',
                                'type' => 'text',
                                'required' => false,
                                'source' => 'manual',
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                        'manualMode' => true,
                    ],
                ],
                [
                    'prompt' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines suite',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-05-01T11:00:00+00:00',
                    'general' => [
                        'titre' => 'Horaire reduit',
                        'description' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines suite',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_horaire_souhaite' => '9h-14h',
                        'ai_periode_concernee' => '2 semaines',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_horaire_souhaite',
                                'label' => 'Horaire souhaite',
                                'type' => 'text',
                                'required' => true,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_periode_concernee',
                                'label' => 'Periode concernee',
                                'type' => 'text',
                                'required' => true,
                                'source' => 'manual',
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                        'manualMode' => true,
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
                'aiDescriptionPrompt' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines suite',
            ],
            [],
            []
        );

        self::assertSame('horaire reduit', $result['suggestedDetails']['ai_besoinpersonnalise'] ?? null);
        self::assertSame('9h-14h', $result['suggestedDetails']['ai_horaire_souhaite'] ?? null);
        self::assertSame('2 semaines', $result['suggestedDetails']['ai_periode_concernee'] ?? null);
        self::assertArrayNotHasKey('ai_horriaireactuel', $result['suggestedDetails']);
        self::assertNotContains(
            'ai_horriaireactuel',
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'])
        );

        $shiftResult = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'je veux un shift nuit 22h-6h uniquement pendant la semaine prochaine',
            ],
            [],
            []
        );

        self::assertArrayNotHasKey('ai_besoinpersonnalise', $shiftResult['suggestedDetails']);
        self::assertNotContains(
            'ai_besoinpersonnalise',
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $shiftResult['dynamicFieldPlan']['add'])
        );
        self::assertSame('Shift de nuit', $shiftResult['suggestedDetails']['ai_type_demande'] ?? null);
    }

    public function testManualDurationSchemaIsReusedWhenPromptSupportsIt(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-05-01T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Horaire reduit',
                        'description' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_demande' => '9h-14h',
                        'ai_horaire_souhaite' => '9h-14h',
                        'ai_duree' => '9h-14h',
                        'ai_extra_infos' => '9h-14h',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_demande',
                                'label' => 'Type demande',
                                'type' => 'text',
                                'required' => true,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_horaire_souhaite',
                                'label' => 'Horaire souhaite',
                                'type' => 'text',
                                'required' => true,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_duree',
                                'label' => 'Duree',
                                'type' => 'text',
                                'required' => false,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_extra_infos',
                                'label' => 'Extra infos',
                                'type' => 'textarea',
                                'required' => false,
                                'source' => 'manual',
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                        'manualMode' => true,
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
                'aiDescriptionPrompt' => 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines',
            ],
            [],
            []
        );

        self::assertSame('9h-14h', $result['suggestedDetails']['ai_horaire_souhaite'] ?? null);
        self::assertSame('2 semaines', $result['suggestedDetails']['ai_duree'] ?? null);
        self::assertSame(
            ['ai_horaire_souhaite', 'ai_duree'],
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'])
        );
    }

    public function testGenerateAutreSuggestionsDoesNotMergeUnrelatedLearnedSchemas(): void
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
                [
                    'prompt' => 'je veux un ecran 32 pouces pour travail sur design',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T11:00:00+00:00',
                    'general' => [
                        'titre' => 'Demande de materiel ecran',
                        'description' => 'je veux un ecran 32 pouces pour travail sur design',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_de_materiel' => 'ecran',
                        'ai_specification_modele_souhaite' => '32 pouces',
                        'ai_usage_justification_metier' => 'travail sur design',
                        'ai_materiel_concerne' => 'ecran',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_de_materiel',
                                'label' => 'Type de materiel',
                                'type' => 'text',
                                'required' => false,
                            ],
                            [
                                'key' => 'ai_specification_modele_souhaite',
                                'label' => 'Specification / modele souhaite',
                                'type' => 'text',
                                'required' => false,
                            ],
                            [
                                'key' => 'ai_usage_justification_metier',
                                'label' => 'Usage / justification metier',
                                'type' => 'textarea',
                                'required' => false,
                            ],
                            [
                                'key' => 'ai_materiel_concerne',
                                'label' => 'Materiel concerne',
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
                'aiDescriptionPrompt' => 'Je veux un shift de nuit 22h-6h uniquement pendant la semaine prochaine.',
            ],
            [],
            []
        );
        $expectedNextWeek = (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');

        self::assertSame('Shift de nuit', $result['suggestedDetails']['ai_type_demande'] ?? null);
        self::assertSame('Nuit', $result['suggestedDetails']['ai_shift_souhaite'] ?? null);
        self::assertSame('22h-6h', $result['suggestedDetails']['ai_horaire_souhaite'] ?? null);
        self::assertSame($expectedNextWeek, $result['suggestedDetails']['ai_periode_concernee'] ?? null);
        self::assertArrayNotHasKey('ai_type_de_materiel', $result['suggestedDetails']);
        self::assertArrayNotHasKey('ai_specification_modele_souhaite', $result['suggestedDetails']);
        self::assertArrayNotHasKey('ai_usage_justification_metier', $result['suggestedDetails']);
        self::assertArrayNotHasKey('ai_materiel_concerne', $result['suggestedDetails']);
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
        $expectedNextWeek = (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');

        self::assertSame('Shift de nuit', $result['suggestedDetails']['ai_type_demande'] ?? null);
        self::assertFalse(str_starts_with($result['suggestedDetails']['ai_type_demande'] ?? '', 'Je veux'));
        self::assertSame('Nuit', $result['suggestedDetails']['ai_shift_souhaite'] ?? null);
        self::assertSame('22h-7h', $result['suggestedDetails']['ai_horaire_souhaite'] ?? null);
        self::assertSame($expectedNextWeek, $result['suggestedDetails']['ai_periode_concernee'] ?? null);
    }

    public function testManualAutreFieldsNeverCallLearningOrLlm(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->expects(self::never())
            ->method('fetchAutreFeedbackSamplesFromDatabase')
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request')
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model',
            20,
            'python-does-not-exist'
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'je veux un badge visiteur demain',
                'manualFieldMode' => true,
                'manualFieldPlan' => [
                    'add' => [
                        [
                            'key' => 'ai_nature_badge',
                            'label' => 'Nature badge',
                            'type' => 'select',
                            'required' => false,
                            'value' => '',
                            'options' => ['Visiteur', 'Permanent'],
                        ],
                    ],
                    'remove' => [],
                    'replaceBase' => true,
                ],
            ],
            [],
            []
        );

        self::assertSame('manual-fields:autre-configured', $result['model']);
        self::assertSame([], $result['suggestedDetails']);
        self::assertTrue($result['dynamicFieldPlan']['replaceBase']);
        self::assertSame('select', $result['dynamicFieldPlan']['add'][0]['type'] ?? null);
        self::assertSame(['Visiteur', 'Permanent'], $result['dynamicFieldPlan']['add'][0]['options'] ?? null);
    }

    public function testManualAutreSuggestionKeepsOneFieldWhenTwoManualFieldsHaveSameValue(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->expects(self::never())
            ->method('fetchAutreFeedbackSamplesFromDatabase')
        ;

        $assistant = new DemandeAiAssistant(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model',
            20,
            'python-does-not-exist'
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'demande casque urgent',
                'manualFieldMode' => true,
                'manualFieldPlan' => [
                    'add' => [
                        [
                            'key' => 'ai_type_materiel',
                            'label' => 'Type materiel',
                            'type' => 'text',
                            'required' => true,
                            'value' => 'Casque',
                        ],
                        [
                            'key' => 'ai_extra_infos',
                            'label' => 'Extra infos',
                            'type' => 'textarea',
                            'required' => true,
                            'value' => 'Casque',
                        ],
                        [
                            'key' => 'ai_contexte_detecte',
                            'label' => 'Contexte detecte',
                            'type' => 'text',
                            'required' => false,
                            'value' => 'Demande materiel',
                            'source' => 'explicit',
                        ],
                    ],
                    'remove' => [],
                    'replaceBase' => true,
                    'manualMode' => true,
                ],
            ],
            [],
            []
        );

        self::assertSame(['ai_type_materiel'], array_column($result['dynamicFieldPlan']['add'], 'key'));
        self::assertSame(['ai_type_materiel' => 'Casque'], $result['suggestedDetails']);
    }

    public function testRequiredSelectFieldIsRenderedEmptyWhenPromptDoesNotMentionAnOption(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'demande de formation externe java',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Formation Java',
                        'description' => 'Demande de formation externe Java',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_nom_formation' => 'Java',
                        'ai_type_formation' => 'Formation externe',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_nom_formation',
                                'label' => 'Nom de la formation',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_type_formation',
                                'label' => 'Type de formation',
                                'type' => 'select',
                                'required' => true,
                                'options' => ['Formation interne', 'Formation externe', 'Certification'],
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
            ])
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request')
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model'
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'demande de formation java',
            ],
            [],
            []
        );

        $fieldsByKey = [];
        foreach ($result['dynamicFieldPlan']['add'] as $field) {
            $fieldsByKey[(string) ($field['key'] ?? '')] = $field;
        }

        self::assertSame('Java', $result['suggestedDetails']['ai_nom_formation'] ?? null);
        self::assertArrayHasKey('ai_type_formation', $fieldsByKey);
        self::assertSame('select', $fieldsByKey['ai_type_formation']['type'] ?? null);
        self::assertSame('', $fieldsByKey['ai_type_formation']['value'] ?? '');
        self::assertArrayNotHasKey('ai_type_formation', $result['suggestedDetails']);
    }

    public function testOptionalLearnedFieldWithoutPromptEvidenceIsNotGenerated(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je veux un badge visiteur pour banque avec justification dossier bancaire',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Badge visiteur',
                        'description' => 'je veux un badge visiteur pour banque avec justification dossier bancaire',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_objet_demande' => 'badge visiteur',
                        'ai_justification_metier' => 'dossier bancaire',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_objet_demande',
                                'label' => 'Objet de la demande',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_justification_metier',
                                'label' => 'Justification metier',
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
                'aiDescriptionPrompt' => 'je veux un badge visiteur',
            ],
            [],
            []
        );

        self::assertSame('badge visiteur', $result['suggestedDetails']['ai_objet_demande'] ?? null);
        self::assertArrayNotHasKey('ai_justification_metier', $result['suggestedDetails']);
    }

    public function testDeletedGeneratedFieldSuppressesThatFieldFamilyOnNextMatch(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'je veux un badge visiteur pour banque',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T09:00:00+00:00',
                    'general' => [
                        'titre' => 'Badge visiteur',
                        'description' => 'je veux un badge visiteur pour banque',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_objet_demande' => 'badge visiteur',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_objet_demande',
                                'label' => 'Objet de la demande',
                                'type' => 'text',
                                'required' => true,
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                    'generatedSnapshot' => [
                        'suggestedDetails' => [
                            'ai_objet_demande' => 'badge visiteur',
                            'ai_justification_metier' => 'banque',
                        ],
                        'dynamicFieldPlan' => [
                            'add' => [
                                [
                                    'key' => 'ai_objet_demande',
                                    'label' => 'Objet de la demande',
                                    'type' => 'text',
                                    'required' => true,
                                    'value' => 'badge visiteur',
                                ],
                                [
                                    'key' => 'ai_justification_metier',
                                    'label' => 'Justification metier',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'value' => 'banque',
                                ],
                            ],
                            'remove' => ['ALL'],
                            'replaceBase' => true,
                        ],
                    ],
                ],
                [
                    'prompt' => 'je veux un badge visiteur pour banque avec justification banque',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Badge visiteur',
                        'description' => 'je veux un badge visiteur pour banque avec justification banque',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_objet_demande' => 'badge visiteur',
                        'ai_justification_metier' => 'banque',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_objet_demande',
                                'label' => 'Objet de la demande',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_justification_metier',
                                'label' => 'Justification metier',
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
                'aiDescriptionPrompt' => 'je veux un badge visiteur pour banque',
            ],
            [],
            []
        );

        self::assertSame('badge visiteur', $result['suggestedDetails']['ai_objet_demande'] ?? null);
        self::assertArrayNotHasKey('ai_justification_metier', $result['suggestedDetails']);
        $fieldKeys = array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add']);
        self::assertNotContains('ai_justification_metier', $fieldKeys);
    }

    public function testExactDatabaseMatchGeneratesFieldsWhoseValuesAreInPrompt(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'demande nettoyage urgent apres degat (cafe renverse sur moquette)',
                    'confirmed' => true,
                    'manual' => false,
                    'createdAt' => '2026-04-26T21:54:59+02:00',
                    'general' => [
                        'titre' => 'demande nettoyage urgent apres degat (cafe renverse sur moquette)',
                        'description' => 'demande nettoyage urgent apres degat (cafe renverse sur moquette)',
                        'priorite' => 'HAUTE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_d_intervention' => 'Nettoyage',
                        'ai_nature_du_degat_incident' => 'Cafe renverse',
                        'ai_surface_element_concerne' => 'Moquette',
                        'besoinPersonnalise' => 'demande nettoyage urgent apres degat (cafe renverse sur moquette)',
                        'descriptionBesoin' => 'demande nettoyage urgent apres degat (cafe renverse sur moquette)',
                        'niveauUrgenceAutre' => 'Urgente',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_d_intervention',
                                'label' => 'Type intervention',
                                'type' => 'text',
                                'required' => false,
                            ],
                            [
                                'key' => 'ai_nature_du_degat_incident',
                                'label' => 'Nature du degat incident',
                                'type' => 'text',
                                'required' => false,
                            ],
                            [
                                'key' => 'ai_surface_element_concerne',
                                'label' => 'Surface element concerne',
                                'type' => 'text',
                                'required' => false,
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                    '_learningSource' => 'database',
                ],
            ])
        ;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::never())
            ->method('request')
        ;

        $assistant = new DemandeAiAssistant(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $repository,
            'test-key',
            'test-model'
        );

        $result = $assistant->generateAutreSuggestions(
            [
                'typeDemande' => 'Autre',
                'aiDescriptionPrompt' => 'demande nettoyage urgent apres degat (cafe renverse sur moquette)',
            ],
            [],
            []
        );

        self::assertSame('Nettoyage', $result['suggestedDetails']['ai_type_d_intervention'] ?? null);
        self::assertSame('Cafe renverse', $result['suggestedDetails']['ai_nature_du_degat_incident'] ?? null);
        self::assertSame('Moquette', $result['suggestedDetails']['ai_surface_element_concerne'] ?? null);
        self::assertSame(
            ['ai_type_d_intervention', 'ai_nature_du_degat_incident', 'ai_surface_element_concerne'],
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'])
        );
        self::assertSame('local-ml:demande/demande_adaptive_model.py', $result['model']);
    }

    public function testManualFeedbackDoesNotLearnServiceGeneratedFields(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'demande casque urgent',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Demande casque',
                        'description' => 'demande casque urgent',
                        'priorite' => 'HAUTE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_materiel' => 'Casque',
                        'ai_extra_infos' => 'urgent',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_materiel',
                                'label' => 'Type materiel',
                                'type' => 'text',
                                'required' => true,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_extra_infos',
                                'label' => 'Extra infos',
                                'type' => 'textarea',
                                'required' => true,
                                'source' => 'generated',
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
                'aiDescriptionPrompt' => 'demande casque urgent',
            ],
            [],
            []
        );

        self::assertSame('Casque', $result['suggestedDetails']['ai_type_materiel'] ?? null);
        self::assertArrayNotHasKey('ai_extra_infos', $result['suggestedDetails']);
        self::assertSame(
            ['ai_type_materiel'],
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'])
        );
    }

    public function testManualFeedbackSampleWithoutConfirmedFlagStillProvidesSchema(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'demande badge visiteur pour client',
                    'confirmed' => false,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Badge visiteur',
                        'description' => 'demande badge visiteur pour client',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_couleur_badge' => 'Bleu',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_couleur_badge',
                                'label' => 'Couleur badge',
                                'type' => 'text',
                                'required' => false,
                                'source' => 'manual',
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                        'manualMode' => true,
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
                'aiDescriptionPrompt' => 'demande badge visiteur pour client',
            ],
            [],
            []
        );

        $fieldKeys = array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add']);
        self::assertContains('ai_couleur_badge', $fieldKeys);
        self::assertArrayNotHasKey('ai_couleur_badge', $result['suggestedDetails']);
    }

    public function testLearnedFeedbackDoesNotUseStaleFieldPlanValueMissingFromFinalDetails(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'demande casque urgent',
                    'confirmed' => true,
                    'manual' => false,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Demande casque',
                        'description' => 'demande casque urgent',
                        'priorite' => 'HAUTE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_materiel' => 'Casque',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_materiel',
                                'label' => 'Type materiel',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'ai_extra_infos',
                                'label' => 'Extra infos',
                                'type' => 'textarea',
                                'required' => false,
                                'value' => 'urgent',
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
                'aiDescriptionPrompt' => 'demande casque urgent',
            ],
            [],
            []
        );

        self::assertSame('Casque', $result['suggestedDetails']['ai_type_materiel'] ?? null);
        self::assertArrayNotHasKey('ai_extra_infos', $result['suggestedDetails']);
        self::assertSame(
            ['ai_type_materiel'],
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'])
        );
    }

    public function testBestMatchedSchemaPreventsCompatibleSamplesFromAddingFields(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'demande casque urgent',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T11:00:00+00:00',
                    'general' => [
                        'titre' => 'Demande casque',
                        'description' => 'demande casque urgent',
                        'priorite' => 'HAUTE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_materiel' => 'Casque',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_materiel',
                                'label' => 'Type materiel',
                                'type' => 'text',
                                'required' => true,
                                'source' => 'manual',
                            ],
                        ],
                        'remove' => ['ALL'],
                        'replaceBase' => true,
                    ],
                ],
                [
                    'prompt' => 'demande casque urgent',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Demande casque',
                        'description' => 'demande casque urgent',
                        'priorite' => 'HAUTE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_materiel' => 'Casque',
                        'ai_extra_infos' => 'Casque',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_materiel',
                                'label' => 'Type materiel',
                                'type' => 'text',
                                'required' => true,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_extra_infos',
                                'label' => 'Extra infos',
                                'type' => 'textarea',
                                'required' => true,
                                'source' => 'manual',
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
                'aiDescriptionPrompt' => 'demande casque urgent',
            ],
            [],
            []
        );

        self::assertSame('Casque', $result['suggestedDetails']['ai_type_materiel'] ?? null);
        self::assertArrayNotHasKey('ai_extra_infos', $result['suggestedDetails']);
        self::assertSame(
            ['ai_type_materiel'],
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'])
        );
    }

    public function testPurposePhraseDoesNotFillOrganizationField(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'attestation de travail pour visa',
                    'confirmed' => true,
                    'manual' => true,
                    'createdAt' => '2026-04-27T10:00:00+00:00',
                    'general' => [
                        'titre' => 'Attestation de travail',
                        'description' => 'attestation de travail pour visa',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_type_attestation' => 'attestation de travail',
                        'ai_motif_contexte' => 'visa',
                        'ai_organisme_destinataire' => 'visa',
                    ],
                    'fieldPlan' => [
                        'add' => [
                            [
                                'key' => 'ai_type_attestation',
                                'label' => 'Type d attestation',
                                'type' => 'text',
                                'required' => true,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_motif_contexte',
                                'label' => 'Motif contexte',
                                'type' => 'text',
                                'required' => false,
                                'source' => 'manual',
                            ],
                            [
                                'key' => 'ai_organisme_destinataire',
                                'label' => 'Organisme destinataire',
                                'type' => 'text',
                                'required' => false,
                                'source' => 'manual',
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
                'aiDescriptionPrompt' => 'attestation de travail pour visa',
            ],
            [],
            []
        );

        self::assertSame('attestation de travail', $result['suggestedDetails']['ai_type_attestation'] ?? null);
        self::assertSame('visa', $result['suggestedDetails']['ai_motif_contexte'] ?? null);
        self::assertArrayNotHasKey('ai_organisme_destinataire', $result['suggestedDetails']);
        self::assertSame(
            ['ai_type_attestation', 'ai_motif_contexte'],
            array_map(static fn (array $field): string => (string) ($field['key'] ?? ''), $result['dynamicFieldPlan']['add'])
        );
    }
}
