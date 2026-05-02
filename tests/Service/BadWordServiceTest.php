<?php

namespace App\Tests\Service;

use App\Service\BadWordService;
use PHPUnit\Framework\TestCase;

class BadWordServiceTest extends TestCase
{
    private BadWordService $service;

    protected function setUp(): void
    {
        $this->service = new BadWordService();
    }

    public function testAnalyzeCleanText(): void
    {
        $result = $this->service->analyze('This is a clean comment');
        
        $this->assertTrue($result['is_clean']);
        $this->assertLessThan(10, $result['score']);
        $this->assertEmpty($result['found']);
    }

    public function testAnalyzeBadWords(): void
    {
        $result = $this->service->analyze('This is stupid');
        
        $this->assertFalse($result['is_clean']);
        $this->assertGreaterThanOrEqual(10, $result['score']);
        $this->assertContains('stupid', $result['found']);
    }

    public function testCensorBadWords(): void
    {
        $result = $this->service->analyze('fuck this shit');
        
        $this->assertStringContainsString('***', $result['censored']);
        $this->assertStringNotContainsString('fuck', $result['censored']);
        $this->assertStringNotContainsString('shit', $result['censored']);
    }

    public function testLeetSpeakDetection(): void
    {
        // Test leet speak: st00pid - detected via Levenshtein similarity (score 7, but threshold is 10)
        $result = $this->service->analyze('St00pid');
        
        // Leet speak is detected but score < 10, so still marked as "clean"
        $this->assertTrue($result['is_clean']);
        $this->assertContains('stupid', $result['found']);
        $this->assertGreaterThan(0, $result['score']);
    }

    public function testRepeatedCharacters(): void
    {
        // Test: stuuuupid, sshhhhit
        $result = $this->service->analyze('stuuuupid');
        
        $this->assertFalse($result['is_clean']);
    }

    public function testSpecialCharacters(): void
    {
        // Test: st@pid - @ is stripped, becomes stpid, similarity to "stupid" detected
        $result = $this->service->analyze('st@pid');
        
        // Detected but score < 10, so still marked as clean
        $this->assertTrue($result['is_clean']);
        $this->assertContains('stupid', $result['found']);
    }

    public function testMultipleBadWords(): void
    {
        $result = $this->service->analyze('stupid and dumb person');
        
        $this->assertFalse($result['is_clean']);
        $this->assertGreaterThan(1, count($result['found']));
    }

    public function testCaseInsensitivity(): void
    {
        $result1 = $this->service->analyze('STUPID');
        $result2 = $this->service->analyze('stupid');
        
        $this->assertEquals($result1['score'], $result2['score']);
    }

    public function testFrenchBadWords(): void
    {
        $result = $this->service->analyze('c\'est con');
        
        $this->assertFalse($result['is_clean']);
        $this->assertContains('con', $result['found']);
    }

    public function testSimilarWords(): void
    {
        // Test Levenshtein similarity (80% threshold)
        // "stupod" vs "stupid" - similarity is high but score only adds 7 (< 10 threshold)
        $result = $this->service->analyze('stupod');
        
        $this->assertTrue($result['is_clean']);
        $this->assertContains('stupid', $result['found']);
    }
}
