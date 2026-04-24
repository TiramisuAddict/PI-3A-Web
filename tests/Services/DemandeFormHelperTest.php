<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Services\DemandeFormHelper;
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
}