<?php 
    namespace App\Services\Offre;
    
    class ContextMatchingService {

        private $jobbertApiUrl;
        private $huggingfaceApiToken;

        public function __construct() {
            $this->jobbertApiUrl = $_ENV['JOBBERT_API_URL'];
            $this->huggingfaceApiToken = $_ENV['HUGGINGFACE_API_TOKEN'];
        }

        public function preprocessRichText(string $text): string {
            if ($text === '') return '';

            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = mb_strtolower($text, 'UTF-8');
            $text = preg_replace('/[^\pL\pN\s]+/u', ' ', $text);
            $text = preg_replace('/\s+/u', ' ', $text);

            return trim($text);
        }

        public function extractTextFromPDF($pdfBlob): string {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($pdfBlob);

            $text = $pdf->getText();

            $text = preg_replace("/\S+@\S+/", "", $text); // Remove emails
            $text = preg_replace("/http\S+/", "", $text); // Remove URLs
            $text = preg_replace("/[^a-zA-Z+#.\\s]/", " ", $text); // Keep letters, numbers, +, #, ., and spaces

            return $text;
        }

        public function match($offreText, $resumeText): string {
            try {
                $jsonBody = json_encode([
                    'inputs' => [
                        'source_sentence' => $offreText,
                        'sentences' => [$resumeText]
                    ]
                ]);

                $ch = curl_init($this->jobbertApiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $this->huggingfaceApiToken,
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonBody)
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    return $response;
                } else {
                    return '[0.0]';
                }
            } catch (\Exception $e) {
                return '[0.0]';
            }
        }

    }
?>