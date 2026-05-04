<?php

namespace App\Tests\Service;

use App\Service\GestionPostManager;
use PHPUnit\Framework\TestCase;

class GestionPostTest extends TestCase
{
    public function testValidPost(): void
    {
        $manager = new GestionPostManager();

        $this->assertTrue($manager->validate(
            'Annonce importante',
            'Ceci est le contenu complet du post.'
        ));
    }

    public function testPostWithoutTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new GestionPostManager();

        $manager->validate(
            '',
            'Ceci est le contenu complet du post.'
        );
    }

    public function testPostWithShortContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new GestionPostManager();

        $manager->validate(
            'Annonce importante',
            'court'
        );
    }
}