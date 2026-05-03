<?php 
    namespace App\Service;
    
    class ContextMatchingService {

        private string $jobbertApiUrl;
        private string $huggingfaceApiToken;

        public function __construct() {
            $this->jobbertApiUrl = $_ENV['JOBBERT_API_URL'];
            $this->huggingfaceApiToken = $_ENV['HUGGINGFACE_API_TOKEN'];
        }

        public function preprocessRichText(string $text): string {
            if ($text === '') return '';

            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = mb_strtolower($text, 'UTF-8');
            $text = preg_replace('/[^\pL\pN\s]+/u', ' ', $text) ?? '';
            $text = preg_replace('/\s+/u', ' ', $text) ?? '';

            return trim($text);
        }

        public function extractTextFromPDF(string $pdfBlob): string {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($pdfBlob);

            $text = $pdf->getText();

            $text = preg_replace("/\S+@\S+/", "", $text) ?? '';
            $text = preg_replace("/http\S+/", "", $text) ?? '';
            $text = preg_replace("/[^a-zA-Z+#.\\s]/", " ", $text) ?? '';

            return $text;
        }

        public function match(string $offreText, string $resumeText): float {
            try {
                $jsonEncoded = json_encode([
                    'inputs' => [
                        'source_sentence' => $offreText,
                        'sentences' => [$resumeText]
                    ]
                ]);
                $jsonBody = $jsonEncoded !== false ? $jsonEncoded : '';

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
                $curlError = curl_error($ch);
                curl_close($ch);

                if (!is_string($response) || $curlError !== '' || $httpCode !== 200) {
                    return 0.0;
                }

                $decoded = json_decode($response, true);
                if (!is_array($decoded)) {
                    return 0.0;
                }

                if (isset($decoded[0]) && is_numeric($decoded[0])) {
                    return abs((float) $decoded[0]);
                }

                return 0.0;
            } catch (\Exception $e) {
                return 0.0;
            }
        }

    }
?>