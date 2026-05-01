<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DemandeController;
use App\Repository\DemandeRepository;
use App\Services\DemandeAiAssistant;
use App\Services\DemandeDecisionAssistant;
use App\Services\DemandeFormHelper;
use App\Services\DemandeMailer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DemandeControllerTest extends TestCase
{
    public function testManualAutreSubmissionDropsGeneratedFieldsAndSameValueDuplicates(): void
    {
        $controller = new DemandeController(
            $this->createMock(DemandeFormHelper::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DemandeRepository::class),
            $this->createMock(DemandeMailer::class),
            $this->createMock(DemandeDecisionAssistant::class),
            $this->createMock(DemandeAiAssistant::class)
        );

        $fieldPlan = [
            'add' => [
                [
                    'key' => 'ai_type_materiel',
                    'label' => 'Type materiel',
                    'type' => 'text',
                    'required' => true,
                    'value' => 'Casque',
                    'source' => 'manual',
                ],
                [
                    'key' => 'ai_extra_infos',
                    'label' => 'Extra infos',
                    'type' => 'textarea',
                    'required' => true,
                    'value' => 'Casque',
                    'source' => 'manual',
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
        ];
        $details = [
            'descriptionBesoin' => 'Besoin casque',
            'ai_type_materiel' => 'Casque',
            'ai_extra_infos' => 'Casque',
            'ai_contexte_detecte' => 'Demande materiel',
        ];

        $sanitize = new ReflectionMethod(DemandeController::class, 'sanitizeManualAutreFieldPlan');
        $sanitize->setAccessible(true);
        $sanitizedPlan = $sanitize->invoke($controller, $fieldPlan, $details);

        self::assertSame(['ai_type_materiel'], array_column($sanitizedPlan['add'], 'key'));
        self::assertSame('manual', $sanitizedPlan['add'][0]['source']);

        $filter = new ReflectionMethod(DemandeController::class, 'filterManualAutreSubmittedDetails');
        $filter->setAccessible(true);
        $filteredDetails = $filter->invoke($controller, $details, $fieldPlan);

        self::assertSame([
            'descriptionBesoin' => 'Besoin casque',
            'ai_type_materiel' => 'Casque',
        ], $filteredDetails);
    }
}
