<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $apiUrl = 'https://api.groq.com/openai/v1/chat/completions',
        private string $model = 'llama-3.1-8b-instant',
    ) {
    }

    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [2, 5, 10];

    /**
     * Generates a concise 3-sentence task description in French using Groq AI.
     * Retries up to 3 times on 429 (rate limit) before giving up.
     *
     * @throws \RuntimeException when the API call fails or the response is malformed
     */
    public function generateTaskDescription(string $taskTitle, string $projectName): string
    {
        $prompt = sprintf(
            'Tu es un assistant expert en gestion de projet. '
            . 'Rédige une description professionnelle de 3 phrases complètes en français pour la tâche "%s" '
            . 'appartenant au projet "%s". '
            . 'La description doit : '
            . '(1) expliquer clairement l\'objectif de la tâche, '
            . '(2) préciser les actions concrètes à réaliser, '
            . '(3) mentionner le livrable ou le résultat attendu. '
            . 'Chaque phrase doit être complète et se terminer par un point. '
            . 'Réponds UNIQUEMENT avec les 3 phrases, sans titre, sans liste, sans commentaire.',
            $taskTitle,
            $projectName
        );

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
            'max_tokens' => 300,
            'top_p' => 0.8,
        ];

        $lastStatusCode = 0;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                sleep(self::RETRY_DELAYS[$attempt - 1] ?? 10);
            }

            $response = $this->httpClient->request('POST', $this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $lastStatusCode = $response->getStatusCode();

            if ($lastStatusCode === 200) {
                $data = $response->toArray(false);
                $text = $data['choices'][0]['message']['content'] ?? null;

                if ($text === null || trim($text) === '') {
                    throw new \RuntimeException('L\'API IA n\'a retourné aucun texte.');
                }

                return trim($text);
            }

            if ($lastStatusCode !== 429) {
                break;
            }
        }

        if ($lastStatusCode === 429) {
            throw new \RuntimeException('Le quota de l\'API IA est momentanément dépassé. Veuillez réessayer dans quelques secondes.');
        }

        throw new \RuntimeException(sprintf('L\'API IA a retourné le code HTTP %d.', $lastStatusCode));
    }
}
