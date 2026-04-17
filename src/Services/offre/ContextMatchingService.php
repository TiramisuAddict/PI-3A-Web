<?php 
    namespace App\Services\Offre;
    
    class ContextMatchingService {

        public function preprocessRichText(string $text): string {
            if ($text === '') return '';

            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = mb_strtolower($text, 'UTF-8');
            $text = preg_replace('/[^\pL\pN\s]+/u', ' ', $text);
            $text = preg_replace('/\s+/u', ' ', $text);

            return trim($text);
        }

        public function extractTextFromPDFblob($pdfBlob): string {
            // using pdfparser bundle to extract text from PDF blob
            return '';
        }

        public function preprocessResumePDF(){}

    }
?>