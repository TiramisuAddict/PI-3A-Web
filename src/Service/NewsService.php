<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class NewsService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $newsApiKey = null,
    ) {
    }

    /**
     * @return array{articles: list<array{title: string, url: string, source: string}>, provider: string, usedFallback: bool}
     */
    public function getTopNews(): array
    {
        if (!$this->newsApiKey || !trim($this->newsApiKey) || $this->newsApiKey === 'none') {
            return $this->fallbackPayload();
        }

        try {
            $queries = [
                '"journee internationale" OR "droits des femmes" OR "fete de l\'arbre" OR RSE OR solidarite',
                'economie OR inflation OR emploi OR entreprise OR "droit du travail" OR recrutement',
                'guerre OR conflit OR tragedie OR catastrophe OR seisme OR inondation OR climat',
            ];

            $mergedArticles = [];
            foreach ($queries as $query) {
                $articles = $this->fetchArticles('https://newsapi.org/v2/everything', [
                    'q' => $query,
                    'language' => 'fr',
                    'sortBy' => 'publishedAt',
                    'pageSize' => 8,
                    'apiKey' => $this->newsApiKey,
                ]);

                foreach ($articles as $article) {
                    $key = mb_strtolower($article['title']);
                    $mergedArticles[$key] = $article;
                }

                if (count($mergedArticles) >= 4) {
                    break;
                }
            }

            if ($mergedArticles !== []) {
                return [
                    'articles' => array_slice(array_values($mergedArticles), 0, 4),
                    'provider' => 'NewsAPI',
                    'usedFallback' => false,
                ];
            }
        } catch (\Throwable) {
        }

        return $this->fallbackPayload();
    }

    /**
     * @return array{articles: list<array{title: string, url: string, source: string}>, provider: string, usedFallback: bool}
     */
    private function fallbackPayload(): array
    {
        return [
            'articles' => [
                [
                    'title' => 'Journee internationale des droits des femmes: comment preparer une prise de parole sobre et respectueuse',
                    'url' => '',
                    'source' => 'Suggestions locales',
                ],
                [
                    'title' => 'Fete de l\'arbre ou action RSE: idees concretes pour mobiliser les equipes localement',
                    'url' => '',
                    'source' => 'Suggestions locales',
                ],
                [
                    'title' => 'Climat economique, inflation, emploi: communiquer avec clarte sans installer d\'inquietude inutile',
                    'url' => '',
                    'source' => 'Suggestions locales',
                ],
                [
                    'title' => 'Guerres, tragedies, catastrophes: trouver le bon ton pour une communication interne humaine et responsable',
                    'url' => '',
                    'source' => 'Suggestions locales',
                ],
            ],
            'provider' => 'Suggestions locales',
            'usedFallback' => true,
        ];
    }

    /**
     * @param array<string, string|int> $query
     * @return list<array{title: string, url: string, source: string}>
     */
    private function fetchArticles(string $url, array $query): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'query' => $query,
        ]);
        $data = $response->toArray(false);

        if (($data['status'] ?? '') !== 'ok' || !isset($data['articles']) || !is_array($data['articles'])) {
            return [];
        }

        $articles = [];

        foreach ($data['articles'] as $article) {
            if (!is_array($article)) {
                continue;
            }

            $title = trim((string) ($article['title'] ?? ''));
            $urlValue = trim((string) ($article['url'] ?? ''));
            $source = trim((string) ($article['source']['name'] ?? 'NewsAPI'));
            $description = trim((string) ($article['description'] ?? ''));

            if ($title === '') {
                continue;
            }

            if (!$this->isRelevantArticle($title, $description)) {
                continue;
            }

            $articles[] = [
                'title' => $title,
                'url' => $urlValue,
                'source' => $source !== '' ? $source : 'NewsAPI',
            ];
        }

        return $articles;
    }

    private function isRelevantArticle(string $title, string $description): bool
    {
        $text = mb_strtolower($title . ' ' . $description);

        $blockedKeywords = [
            'foot',
            'football',
            'basket',
            'tennis',
            'rugby',
            'parquet',
            'templeuve',
            'code de la route',
            'auto-ecole',
            'tele-realite',
            'people',
            'celebrite',
            'pma',
            'couple',
        ];

        foreach ($blockedKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return false;
            }
        }

        $preferredKeywords = [
            'femme',
            'femmes',
            'journee internationale',
            'arbre',
            'rse',
            'solidarite',
            'emploi',
            'travail',
            'entreprise',
            'economie',
            'inflation',
            'salaire',
            'recrutement',
            'droit du travail',
            'guerre',
            'conflit',
            'tragedie',
            'catastrophe',
            'seisme',
            'inondation',
            'climat',
            'onu',
            'humanitaire',
        ];

        foreach ($preferredKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
