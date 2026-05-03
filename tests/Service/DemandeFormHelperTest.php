<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Enum\DemandeStatus;
use App\Service\DemandeFormHelper;
use PHPUnit\Framework\TestCase;

final class DemandeFormHelperTest extends TestCase
{
    public function testResolvesCanonicalCategoryAndTypeForGestionDemandeSynonyms(): void
    {
        $helper = new DemandeFormHelper();

        self::assertSame('Administrative', $helper->resolveCanonicalCategory('finance', null));
        self::assertSame('Conge', $helper->resolveCanonicalType('congé', null));
        self::assertSame('Informatique', $helper->resolveCanonicalCategory(null, 'Acces systeme'));
    }

    public function testGetsFieldsForKnownType(): void
    {
        $helper = new DemandeFormHelper();

        $fields = $helper->getFieldsForType('Conge');

        self::assertNotEmpty($fields);
        self::assertSame('typeConge', $fields[0]['key']);
        self::assertTrue($fields[0]['required']);
    }

    public function testGetCategoriesAndPrioritesExposeKnownValues(): void
    {
        $helper = new DemandeFormHelper();

        self::assertSame([
            'Ressources Humaines',
            'Administrative',
            'Informatique',
            'Formation',
            'Organisation du travail',
            'Autre',
        ], $helper->getCategories());

        self::assertSame([
            'HAUTE',
            'NORMALE',
            'BASSE',
        ], $helper->getPriorites());
    }

    public function testGetStatusesMirrorDemandeStatusEnumValues(): void
    {
        $helper = new DemandeFormHelper();

        self::assertSame(DemandeStatus::values(), $helper->getStatuses());
    }
}
