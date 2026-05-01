<?php

namespace App\Tests\Service;

use App\Service\AnnouncementTemplateService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AnnouncementTemplateServiceTest extends TestCase
{
    private bool $hadToken = false;
    private string $previousToken = '';

    protected function tearDown(): void
    {
        if ($this->hadToken) {
            $_ENV['HUGGING_FACE_EVENT'] = $this->previousToken;
        } else {
            unset($_ENV['HUGGING_FACE_EVENT']);
        }
    }

    public function testUsesHuggingFaceWithRichContent(): void
    {
        $this->rememberToken();
        $_ENV['HUGGING_FACE_EVENT'] = 'hf-test-token';
        
        // Simuler une réponse riche de Hugging Face
        $richHfResponse = $this->getSampleRichHrContent();
        
        $service = new AnnouncementTemplateService(new MockHttpClient([
            new MockResponse(json_encode([
                ['generated_text' => $richHfResponse]
            ], JSON_THROW_ON_ERROR)),
        ]));
        
        $draft = $service->generateDraft('Nouveau système de congés payés');
        
        // Vérifications du contenu enrichi
        $this->assertSame('Hugging Face', $draft['provider']);
        $this->assertFalse($draft['usedFallback']);
        
        $content = $draft['content'];
        
        // Vérifier la présence de TOUTES les sections importantes
        $this->assertStringContainsString('TITRE', $content);
        $this->assertStringContainsString('CONTEXTE', $content);
        $this->assertStringContainsString('OBJECTIFS', $content);
        $this->assertStringContainsString('IMPACT', $content);
        $this->assertStringContainsString('PLAN D\'ACTION', $content);
        $this->assertStringContainsString('FAQ', $content);
        $this->assertStringContainsString('CONTACTS', $content);
        $this->assertStringContainsString('INDICATEURS', $content);
        
        // Vérifier la longueur (contenu substantiel)
        $this->assertGreaterThan(1000, strlen($content));
        
        // Vérifier les éléments RH professionnels
        $rhElements = ['collaborateurs', 'managers', 'formation', 'accompagnement', 'KPI'];
        foreach ($rhElements as $element) {
            $this->assertStringContainsString($element, mb_strtolower($content));
        }
        
        // Vérifier la présence d'un tableau (markdown)
        $this->assertMatchesRegularExpression('/\|.*\|.*\|/', $content);
    }
    
    public function testFallbackAlsoProvidesRichContent(): void
    {
        $service = new AnnouncementTemplateService(new MockHttpClient());
        
        $draft = $service->generateDraft('Jour férié du 1er mai');
        
        $this->assertTrue($draft['usedFallback']);
        $content = $draft['content'];
        
        // Vérifier que même le fallback est riche
        $this->assertGreaterThan(500, strlen($content));
        $this->assertStringContainsString('TITRE', $content);
        $this->assertStringContainsString('FAQ', $content);
        $this->assertStringContainsString('CONTACTS', $content);
        $this->assertStringContainsString('astreinte', mb_strtolower($content));
    }
    
    public function testValidatesContentQuality(): void
    {
        $this->rememberToken();
        $_ENV['HUGGING_FACE_EVENT'] = 'hf-test-token';
        
        // Contenu trop court et pauvre
        $poorContent = "Bonjour, c'est les vacances. Bonnes vacances à tous !";
        
        $service = new AnnouncementTemplateService(new MockHttpClient([
            new MockResponse(json_encode([
                ['generated_text' => $poorContent]
            ], JSON_THROW_ON_ERROR)),
        ]));
        
        $draft = $service->generateDraft('Vacances');
        
        // Doit utiliser le fallback car contenu invalide
        $this->assertTrue($draft['usedFallback']);
        $this->assertGreaterThan(500, strlen($draft['content']));
    }
    
    public function testHandlesMultipleAnnouncementTypes(): void
    {
        $this->rememberToken();
        $_ENV['HUGGING_FACE_EVENT'] = 'hf-test-token';
        
        $testCases = [
            'Nouveau logiciel RH' => ['logiciel', 'formation', 'support'],
            'Changement d\'horaires' => ['horaires', 'organisation', 'planning'],
            'Prime exceptionnelle' => ['prime', 'montant', 'versement'],
            'Recrutement massif' => ['recrutement', 'postes', 'candidatures'],
        ];
        
        foreach ($testCases as $headline => $keywords) {
            $mockResponse = $this->generateMockRichResponse($headline);
            
            $service = new AnnouncementTemplateService(new MockHttpClient([
                new MockResponse(json_encode([
                    ['generated_text' => $mockResponse]
                ], JSON_THROW_ON_ERROR)),
            ]));
            
            $draft = $service->generateDraft($headline);
            
            // Vérifier que le contenu est adapté au sujet
            foreach ($keywords as $keyword) {
                $this->assertStringContainsString(
                    $keyword, 
                    mb_strtolower($draft['content']),
                    "Le mot-clé '{$keyword}' est absent pour l'annonce '{$headline}'"
                );
            }
            
            $this->assertGreaterThan(800, strlen($draft['content']));
        }
    }
    
    private function getSampleRichHrContent(): string
    {
        return "### 📢 TITRE DE L'ANNONCE
Nouveau système de gestion des congés payés

### 📅 DATE ET CONTEXTE
**Date de l'annonce** : 20/04/2026
**Contexte général** : Après 6 mois de travail collaboratif avec les représentants du personnel, nous sommes fiers d'annoncer le lancement de notre nouvelle plateforme de gestion des congés. Ce projet répond à une attente forte des équipes pour plus d'autonomie et de transparence.

**Enjeux pour l'entreprise** : Moderniser notre outil RH, réduire les erreurs de saisie de 80%, et offrir une expérience collaborateur simplifiée.

### 🎯 OBJECTIFS DE L'ANNONCE
1. **Autonomie totale** : Les collaborateurs gèrent eux-mêmes leurs demandes
2. **Visibilité en temps réel** : Solde disponible instantanément
3. **Validation dématérialisée** : Workflow automatique vers les managers

### 👥 IMPACT SUR LES ÉQUIPES
**Pour les collaborateurs** :
- Accès 24/7 à son compteur de congés
- Demandes en 3 clics via l'application mobile
- Notifications automatiques de validation

**Pour les managers** :
- Vue d'équipe en temps réel
- Validation depuis smartphone
- Anticipation des absences sur 6 mois

**Pour les services supports** :
- RH : Fin des saisies manuelles
- IT : Support utilisateur niveau 1
- Paie : Intégration automatique

### 📋 PLAN D'ACTION DÉTAILLÉ
| Action | Responsable | Délai | Ressources nécessaires |
|--------|-------------|-------|------------------------|
| Formation utilisateurs | RH | J+5 | Tutoriels vidéo |
| Migration des soldes | IT/RH | J+7 | Script automatisé |
| Go-live officiel | Direction | J+15 | Communication |
| Support renforcé | Support | J+15 à J+30 | 3 hotlineurs dédiés |

### ⚙️ MODALITÉS PRATIQUES
**Calendrier** : Lancement le 15 mai 2026
**Procédures** : Tutoriel disponible sur l'intranet
**Documents requis** : Aucun, tout est dématérialisé
**Formations associées** : Webinaire de 30min obligatoire

### 💬 QUESTIONS FRÉQUENTES (FAQ)
**Q1 :** Que deviennent mes congés déjà posés ?
**R1 :** Ils sont automatiquement migrés vers la nouvelle plateforme.

**Q2 :** Puis-je poser un congé pour aujourd'hui ?
**R2 :** Délai minimum de 48h sauf urgence validée par manager.

**Q3 :** Y a-t-il une version mobile ?
**R3 :** Oui, application disponible sur iOS et Android.

### 📞 CONTACTS ET SUPPORT
- **Référent RH** : Sophie Martin - s.martin@entreprise.com - 01 23 45 67 89
- **Support technique** : helpdesk-conges@entreprise.com
- **Permanences** : 9h-18h du lundi au vendredi
- **Réunions d'information** : 22/04 à 14h et 24/04 à 10h

### 📅 PROCHAINES ÉTAPES
- **Semaine 1** : Formations et documentation
- **Semaine 2** : Migration des données
- **Semaine 3** : Tests utilisateurs
- **Semaine 4** : Déploiement général

### ✅ INDICATEURS DE SUIVI
Nous mesurerons le succès via :
- Taux d'adoption à J+30 : 95% cible
- Délai moyen de validation : <24h
- Satisfaction utilisateur : >8/10
- Réduction des erreurs : -80%

### 🙏 MOT DE LA DIRECTION
Merci à toutes les équipes qui ont contribué à ce projet ambitieux. Cette nouvelle plateforme est un exemple de notre capacité à innover ensemble pour améliorer votre quotidien. Nous comptons sur votre feedback pour continuer à l'enrichir dans les prochains mois.";
    }
    
    private function generateMockRichResponse(string $headline): string
    {
        // Génère une réponse mockée adaptée au sujet
        return "### 📢 TITRE DE L'ANNONCE\n" . ucfirst($headline) . "\n\n### 📅 DATE ET CONTEXTE\n**Date de l'annonce** : 20/04/2026\n**Contexte général** : Cette annonce importante concerne " . $headline . " et aura un impact significatif sur l'organisation.\n\n### 👥 IMPACT SUR LES ÉQUIPES\n**Pour les collaborateurs** : Adaptation des process quotidiens\n**Pour les managers** : Nouvelles responsabilités\n\n### 📋 PLAN D'ACTION DÉTAILLÉ\n| Action | Responsable | Délai |\n|--------|-------------|-------|\n| Communication | Direction | J0 |\n| Formation | RH | J+7 |\n\n### 💬 QUESTIONS FRÉQUENTES (FAQ)\n**Q1 :** Quand cela s'applique-t-il ?\n**R1 :** À partir du mois prochain.\n\n### 📞 CONTACTS ET SUPPORT\n- **Service RH** : rh@entreprise.com\n\n### 🙏 MOT DE LA DIRECTION\nMerci pour votre engagement et votre professionnalisme.";
    }
    
    private function rememberToken(): void
    {
        $this->hadToken = array_key_exists('HUGGING_FACE_EVENT', $_ENV);
        $this->previousToken = $this->hadToken ? (string) $_ENV['HUGGING_FACE_EVENT'] : '';
    }
}