<?php

namespace App\Tests\Service;

use App\Service\ToxicIntentService;
use PHPUnit\Framework\TestCase;

class ToxicIntentServiceTest extends TestCase
{
    private ToxicIntentService $service;

    protected function setUp(): void
    {
        $this->service = new ToxicIntentService(dirname(__DIR__, 2));
    }

    public function testIndirectInsultIsBlocked(): void
    {
        $result = $this->service->analyze('Sorry, I can\'t think of an insult good enough for you to understand.');

        $this->assertTrue($result['is_toxic']);
        $this->assertArrayHasKey('insult', $result['labels']);
    }

    public function testConstructiveCriticismIsAllowed(): void
    {
        $result = $this->service->analyze('I disagree with this implementation because it has bugs.');

        $this->assertFalse($result['is_toxic']);
    }
}
