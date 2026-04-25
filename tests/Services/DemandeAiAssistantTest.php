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

        self::assertSame('database-feedback:autre-confirmed-match', $result['model']);
        self::assertTrue($result['dynamicFieldPlan']['replaceBase']);
        self::assertArrayHasKey('ai_type_attestation', $result['suggestedDetails']);
        self::assertArrayHasKey('ai_organisme_destinataire', $result['suggestedDetails']);
        self::assertArrayNotHasKey('besoinPersonnalise', $result['suggestedDetails']);
        self::assertArrayNotHasKey('descriptionBesoin', $result['suggestedDetails']);
        self::assertArrayNotHasKey('dateSouhaiteeAutre', $result['suggestedDetails']);
        self::assertSame('attestation de salaire', strtolower((string) $result['suggestedDetails']['ai_type_attestation']));
        self::assertSame('banque', strtolower((string) $result['suggestedDetails']['ai_organisme_destinataire']));
    }

    public function testGenerateAutreSuggestionsReusesLearnedFieldsAndAddsDateFromPrompt(): void
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
                'aiDescriptionPrompt' => 'Attestation de salaire pour la banque le 21 mai',
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

        self::assertSame('database-feedback:autre-confirmed-match', $result['model']);
        self::assertArrayHasKey('ai_type_attestation', $result['suggestedDetails']);
        self::assertArrayHasKey('ai_organisme_destinataire', $result['suggestedDetails']);
        self::assertArrayHasKey('ai_date_souhaitee', $result['suggestedDetails']);
        self::assertArrayNotHasKey('montant', $result['suggestedDetails']);
        self::assertArrayNotHasKey('ai_montant', $result['suggestedDetails']);
        self::assertArrayNotHasKey('descriptionBesoin', $result['suggestedDetails']);
        self::assertSame('attestation de salaire', strtolower((string) $result['suggestedDetails']['ai_type_attestation']));
        self::assertStringContainsString('banque', strtolower((string) $result['suggestedDetails']['ai_organisme_destinataire']));
        self::assertMatchesRegularExpression('/^\d{4}-05-21$/', (string) $result['suggestedDetails']['ai_date_souhaitee']);
    }

    public function testGenerateAutreSuggestionsUsesGenericLearnedSchemaWithDatePlaceAndAmount(): void
    {
        $repository = $this->createMock(DemandeRepository::class);
        $repository
            ->method('fetchAutreFeedbackSamplesFromDatabase')
            ->willReturn([
                [
                    'prompt' => 'reservation salle innovation pour atelier produit',
                    'confirmed' => true,
                    'general' => [
                        'titre' => 'Reservation salle innovation',
                        'description' => 'Reservation d une salle pour organiser un atelier produit.',
                        'priorite' => 'NORMALE',
                        'categorie' => 'Autre',
                        'typeDemande' => 'Autre',
                    ],
                    'details' => [
                        'ai_salle' => 'Innovation',
                        'ai_motif' => 'atelier produit',
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
                'aiDescriptionPrompt' => 'Reservation salle Innovation pour atelier produit le 4 juin a Tunis budget 300 TND',
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
            ]
        );

        self::assertSame('database-feedback:autre-confirmed-match', $result['model']);
        self::assertArrayHasKey('ai_salle', $result['suggestedDetails']);
        self::assertArrayHasKey('ai_motif', $result['suggestedDetails']);
        self::assertArrayHasKey('ai_date_souhaitee', $result['suggestedDetails']);
        self::assertArrayHasKey('ai_lieu_souhaite', $result['suggestedDetails']);
        self::assertArrayHasKey('ai_montant', $result['suggestedDetails']);
        self::assertArrayNotHasKey('descriptionBesoin', $result['suggestedDetails']);
        self::assertMatchesRegularExpression('/^\d{4}-06-04$/', (string) $result['suggestedDetails']['ai_date_souhaitee']);
        self::assertSame('tunis', strtolower((string) $result['suggestedDetails']['ai_lieu_souhaite']));
        self::assertSame('300', (string) $result['suggestedDetails']['ai_montant']);
    }
}
