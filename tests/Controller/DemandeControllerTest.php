<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DemandeController;
use App\Repository\DemandeRepository;
use App\Service\DemandeAiAssistant;
use App\Service\DemandeDecisionAssistant;
use App\Service\DemandeFormHelper;
use App\Service\DemandeMailer;
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

    public function testGeneratedAutreSubmissionDropsEmptyCurrentScheduleFieldWithoutPromptEvidence(): void
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
                    'key' => 'ai_horaire_actuel',
                    'label' => 'Horaire actuel',
                    'type' => 'text',
                    'required' => true,
                    'value' => '',
                    'source' => 'manual',
                ],
                [
                    'key' => 'ai_horaire_souhaite',
                    'label' => 'Horaire souhaite',
                    'type' => 'text',
                    'required' => true,
                    'value' => '9h-14h',
                    'source' => 'manual',
                ],
            ],
            'remove' => ['ALL'],
            'replaceBase' => true,
            'manualMode' => false,
        ];
        $details = [
            'ai_horaire_actuel' => '',
            'ai_horaire_souhaite' => '9h-14h',
        ];

        $sanitize = new ReflectionMethod(DemandeController::class, 'sanitizeGeneratedAutreFieldPlan');
        $sanitize->setAccessible(true);
        $sanitizedPlan = $sanitize->invokeArgs(
            $controller,
            [$fieldPlan, &$details, 'je souhaite un horaire reduit: 9h-14h pendant 2 semaines']
        );

        self::assertSame(['ai_horaire_souhaite'], array_column($sanitizedPlan['add'], 'key'));
        self::assertArrayNotHasKey('ai_horaire_actuel', $details);
    }

    public function testManualAutreFeedbackRequiresExplicitConfirmationBeforeLearning(): void
    {
        $controller = new DemandeController(
            $this->createMock(DemandeFormHelper::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DemandeRepository::class),
            $this->createMock(DemandeMailer::class),
            $this->createMock(DemandeDecisionAssistant::class),
            $this->createMock(DemandeAiAssistant::class)
        );

        $requiresConfirmation = new ReflectionMethod(DemandeController::class, 'requiresAutreAiConfirmation');
        $requiresConfirmation->setAccessible(true);
        $acceptedFeedback = new ReflectionMethod(DemandeController::class, 'isAcceptedAutreFeedback');
        $acceptedFeedback->setAccessible(true);

        self::assertTrue($requiresConfirmation->invoke($controller, 'Autre', false, true, false));
        self::assertFalse($acceptedFeedback->invoke($controller, 'Autre', false, true, false));
        self::assertFalse($requiresConfirmation->invoke($controller, 'Autre', false, true, true));
        self::assertTrue($acceptedFeedback->invoke($controller, 'Autre', false, true, true));
    }
}
