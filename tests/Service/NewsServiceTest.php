<?php

namespace App\Tests\Service;

use App\Service\NewsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class NewsServiceTest extends TestCase
{
    public function testReturnsFallbackWhenApiKeyMissing(): void
    {
        $service = new NewsService(new MockHttpClient(), '');

        $payload = $service->getTopNews();

        $this->assertTrue($payload['usedFallback']);
        $this->assertSame('Suggestions locales', $payload['provider']);
        $this->assertNotEmpty($payload['articles']);
    }

    public function testReturnsRelevantRemoteArticlesWhenApiResponds(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 'ok',
                'articles' => [
                    [
                        'title' => 'Journee internationale des droits des femmes: plusieurs entreprises renforcent leurs engagements',
                        'description' => 'Un sujet RH et societe.',
                        'url' => 'https://example.com/article',
                        'source' => ['name' => 'Example'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'status' => 'ok',
                'articles' => [],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'status' => 'ok',
                'articles' => [],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new NewsService($client, 'demo-key');
        $payload = $service->getTopNews();

        $this->assertFalse($payload['usedFallback']);
        $this->assertSame('NewsAPI', $payload['provider']);
        $this->assertSame('Journee internationale des droits des femmes: plusieurs entreprises renforcent leurs engagements', $payload['articles'][0]['title']);
        $this->assertSame('https://example.com/article', $payload['articles'][0]['url']);
    }

    public function testFallsBackWhenRemoteArticlesAreIrrelevant(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 'ok',
                'articles' => [
                    [
                        'title' => 'P1 dames - maintien sur le parquet apres un match',
                        'description' => 'Article sportif.',
                        'url' => 'https://example.com/sport',
                        'source' => ['name' => 'Sport'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'status' => 'ok',
                'articles' => [],
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'status' => 'ok',
                'articles' => [],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = new NewsService($client, 'demo-key');
        $payload = $service->getTopNews();

        $this->assertTrue($payload['usedFallback']);
        $this->assertSame('Suggestions locales', $payload['provider']);
    }
}
