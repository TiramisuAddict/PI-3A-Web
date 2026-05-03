<?php

namespace App\Service;

use App\Entity\CompetenceEmploye;
use App\Entity\Employe;
use Doctrine\ORM\EntityManagerInterface;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpClient\HttpClient;

final class CvExtractionService
{
    /**
     * @return array<string, mixed>
     */
    public function extractAndPersistForEmploye(Employe $employe, EntityManagerInterface $entityManager): array
    {
        $groqApiUrl = $this->readEnv('GROQ_API_URL');
        $groqApiKey = $this->readEnv('GROQ_API_KEY');
        $groqModel = $this->readEnv('GROQ_MODEL');

        if ($groqApiUrl === '' || $groqApiKey === '' || $groqModel === '') {
            return [
                'success' => false,
                'error' => 'Configuration Groq manquante (GROQ_API_URL, GROQ_API_KEY ou GROQ_MODEL).',
            ];
        }

        $cvBinary = $employe->getCvData();
        $cvText = $this->extractTextFromCvBinary($cvBinary);
        $extracted = $this->extractWithGroq($cvText, $groqApiUrl, $groqApiKey, $groqModel);
        if (isset($extracted['error'])) {
            return [
                'success' => false,
                'error' => (string) $extracted['error'],
            ];
        }

        $skills = is_array($extracted['skills'] ?? null) ? $extracted['skills'] : [];
        $formations = is_array($extracted['formations'] ?? null) ? $extracted['formations'] : [];
        $experience = is_array($extracted['experience'] ?? null) ? $extracted['experience'] : [];

        $competenceRepo = $entityManager->getRepository(CompetenceEmploye::class);
        $competenceEmploye = $competenceRepo->findOneBy(['employe' => $employe]);
        if ($competenceEmploye === null) {
            $competenceEmploye = new CompetenceEmploye();
            $competenceEmploye->setEmploye($employe);
        }

        $competenceEmploye->setSkills(json_encode(array_values($skills), JSON_UNESCAPED_UNICODE));
        $competenceEmploye->setFormations(json_encode(array_values($formations), JSON_UNESCAPED_UNICODE));
        $competenceEmploye->setExperience(json_encode(array_values($experience), JSON_UNESCAPED_UNICODE));

        $entityManager->persist($competenceEmploye);

        return [
            'success' => true,
            'data' => [
                'skills_count' => count($skills),
                'formations_count' => count($formations),
                'experience_count' => count($experience),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractWithGroq(string $cvText, string $groqApiUrl, string $groqApiKey, string $groqModel): array
    {
        $systemMessage = "You are a strict data extraction tool. Return only a valid JSON object with the exact required structure and keys. No markdown, no explanations, no extra fields.";

        $userMessage = "Extract structured data from this CV and return ONLY a JSON object with this exact structure:\n"
            . "{\n"
            . "  \"skills\": [\"string\"],\n"
            . "  \"formations\": [\n"
            . "    {\"degree\": \"string\", \"institution\": \"string\", \"year\": \"string\"}\n"
            . "  ],\n"
            . "  \"experience\": [\n"
            . "    {\"job_title\": \"string\", \"company\": \"string\", \"duration\": \"string\", \"responsibilities\": [\"string\"]}\n"
            . "  ]\n"
            . "}\n\n"
            . "Rules:\n"
            . "- Keep exactly these top-level keys: skills, formations, experience\n"
            . "- Keep exactly these nested keys\n"
            . "- If information is missing, use empty arrays []\n"
            . "- Return only JSON\n\n"
            . "CV:\n\n"
            . $cvText
            . "\n";

        $payload = [
            'model' => $groqModel,
            'messages' => [
                ['role' => 'system', 'content' => $systemMessage],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
        ];

        $client = HttpClient::create();
        $response = $client->request('POST', $groqApiUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $groqApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => 90,
        ]);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);
        if ($statusCode !== 200) {
            return ['error' => 'Groq API error: ' . $statusCode . ' - ' . $content];
        }

        $decoded = json_decode($content, true);
        $rawText = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($rawText) || trim($rawText) === '') {
            return ['error' => 'Reponse vide du modele.'];
        }

        $structured = $this->decodeModelJson($rawText);
        if (!is_array($structured)) {
            $preview = mb_substr(trim($rawText), 0, 250);
            return ['error' => 'Le modele n\'a pas retourne un JSON valide. Reponse: ' . $preview];
        }

        return $structured;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeModelJson(string $rawText): ?array
    {
        $direct = json_decode($rawText, true);
        if (is_array($direct)) {
            return $direct;
        }

        $cleanJson = trim(str_replace(['```json', '```'], '', $rawText));
        $fromClean = json_decode($cleanJson, true);
        if (is_array($fromClean)) {
            return $fromClean;
        }

        $firstBrace = strpos($cleanJson, '{');
        $lastBrace = strrpos($cleanJson, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonBlock = substr($cleanJson, $firstBrace, $lastBrace - $firstBrace + 1);
            $fromBlock = json_decode($jsonBlock, true);
            if (is_array($fromBlock)) {
                return $fromBlock;
            }
        }

        return null;
    }

    private function extractTextFromCvBinary(string $cvBinary): ?string
    {
        if (!str_starts_with($cvBinary, '%PDF-')) {
            return trim($cvBinary);
        }

        $text = $this->extractPdfTextWithPhpParser($cvBinary);
        if ($text !== null && trim($text) !== '') {
            return trim($text);
        }

        return null;
    }

    private function extractPdfTextWithPhpParser(string $cvBinary): ?string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseContent($cvBinary);
            $text = $pdf->getText();

            return $text;
        } catch (\Throwable) {
            return null;
        }
    }

    private function readEnv(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

}
