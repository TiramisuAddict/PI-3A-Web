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
}
