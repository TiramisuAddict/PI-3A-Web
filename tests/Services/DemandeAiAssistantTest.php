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

        self::assertSame('local-ml:demande_ai_model.py', $result['model']);
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

        self::assertSame('local-ml:demande_ai_model.py', $result['model']);
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

        self::assertContains($result['model'], ['local-ml:demande_ai_model.py', 'database-feedback:autre-confirmed-match']);
        self::assertArrayNotHasKey('ai_salle', $result['suggestedDetails']);
        self::assertArrayNotHasKey('ai_motif', $result['suggestedDetails']);
        self::assertNotEmpty($result['suggestedDetails']);
    }
}
