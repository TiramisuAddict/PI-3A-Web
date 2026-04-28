<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ProjectRiskReportService
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [2, 5, 10];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $apiUrl = 'https://api.groq.com/openai/v1/chat/completions',
        private string $model = 'llama-3.1-8b-instant',
    ) {
    }

    /**
     * Generates a short 2-3 sentence conclusion in French summarising project health
     * and the main risk. Data comes from the controller's computed statistics.
     *
     * @param array{
     *   name: string,
     *   statut: string,
     *   priorite: string|null,
     *   dateFinPrevue: string|null,
     *   daysLeft: int|null,
     *   totalTasks: int,
     *   termineesCount: int,
     *   enCoursCount: int,
     *   aFaireCount: int,
     *   bloqueeCount: int,
     *   retardCount: int,
     *   completionPct: int,
     *   teamSummary: string
     * } $data
     *
     * @throws \RuntimeException
     */
    public function generateConclusion(array $data): string
    {
        $lines = [
            sprintf('Projet "%s" — statut : %s, priorité : %s.', $data['name'], $data['statut'], $data['priorite'] ?? 'non définie'),
            sprintf('Avancement : %d%% (%d/%d tâches terminées), %d en cours, %d à faire, %d bloquée(s).', $data['completionPct'], $data['termineesCount'], $data['totalTasks'], $data['enCoursCount'], $data['aFaireCount'], $data['bloqueeCount']),
        ];

        if ($data['retardCount'] > 0) {
            $lines[] = sprintf('%d tâche(s) ont dépassé leur date limite.', $data['retardCount']);
        }

        if ($data['daysLeft'] !== null) {
            $lines[] = $data['daysLeft'] >= 0
                ? sprintf('Délai restant : %d jour(s) avant la date de fin prévue (%s).', $data['daysLeft'], $data['dateFinPrevue'])
                : sprintf('Le projet est en retard de %d jour(s) (fin prévue : %s).', abs($data['daysLeft']), $data['dateFinPrevue']);
        }

        if ($data['teamSummary'] !== '') {
            $lines[] = 'Équipe : ' . $data['teamSummary'] . '.';
        }

        $context = implode(' ', $lines);

        $prompt = "Tu es un expert en gestion de projet. "
            . "À partir des données suivantes, rédige une conclusion professionnelle de 2 à 3 phrases en français. "
            . "La conclusion doit identifier le risque principal et donner une recommandation concrète. "
            . "Sois direct. Réponds UNIQUEMENT avec les phrases, sans titre ni liste.\n\n"
            . $context;

        $payload = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
            'max_tokens'  => 200,
            'top_p'       => 0.8,
        ];

        $lastStatusCode = 0;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                sleep(self::RETRY_DELAYS[$attempt - 1] ?? 10);
            }

            $response = $this->httpClient->request('POST', $this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
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
