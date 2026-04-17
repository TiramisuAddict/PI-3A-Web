<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiService
{
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
    ) {
    }

    /**
     * Generates a concise 2-3 sentence task description in French using Gemini AI.
     *
     * @throws \RuntimeException when the API call fails or the response is malformed
     */
    public function generateTaskDescription(string $taskTitle, string $projectName): string
    {
        $prompt = sprintf(
            'Tu es un assistant de gestion de projet. '
            . 'Génère une description concise de 2 à 3 phrases en français pour la tâche intitulée "%s" '
            . 'dans le cadre du projet "%s". '
            . 'La description doit être professionnelle, claire et expliquer l\'objectif de la tâche ainsi que les actions attendues. '
            . 'Réponds uniquement avec la description, sans introduction ni commentaire.',
            $taskTitle,
            $projectName
        );

        $response = $this->httpClient->request('POST', self::API_URL, [
            'query' => ['key' => $this->apiKey],
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature'     => 0.7,
                    'maxOutputTokens' => 256,
                ],
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('Gemini API a retourné le code HTTP %d.', $statusCode));
        }

        $data = $response->toArray(false);

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text === null || trim($text) === '') {
            throw new \RuntimeException('Gemini API n\'a retourné aucun texte.');
        }

        return trim($text);
    }
}
