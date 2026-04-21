<?php

namespace App\Services;

use App\Repository\DemandeRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DemandeAiAssistant
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly DemandeRepository $demandeRepository,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 20,
        private readonly string $pythonExecutable = 'python',
        private readonly string $pythonScriptPath = ''
    ) {
    }

    /**
     * @param array<string, mixed> $generalContext
     * @param array<string, mixed> $currentDetails
     * @param array<int, array<string, mixed>> $autreFields
     * @return array<string, mixed>
     */
    public function generateAutreSuggestions(array $generalContext, array $currentDetails, array $autreFields): array
    {
        $typeDemande = trim((string) ($generalContext['typeDemande'] ?? 'Autre'));
        if ('' !== $typeDemande && 'Autre' !== $typeDemande) {
            throw new \RuntimeException('L assistant de generation de champs est reserve au type Autre.');
        }

        $sourceText = $this->firstNonEmpty(
            trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
            trim((string) ($generalContext['description'] ?? '')),
            trim((string) ($generalContext['titre'] ?? ''))
        );

        if ('' === $sourceText && [] === $currentDetails) {
            throw new \RuntimeException('Ajoutez une description initiale avant de lancer la generation IA pour le type Autre.');
        }

        $allowedKeys = [];
        $requiredKeys = [];
        $fieldLabels = [];
        $selectOptions = [];

        foreach ($autreFields as $field) {
            $key = (string) ($field['key'] ?? '');
            if ('' === $key) {
                continue;
            }

            $allowedKeys[] = $key;
            $requiredKeys[$key] = (bool) ($field['required'] ?? false);
            $fieldLabels[$key] = (string) ($field['label'] ?? $key);

            if (($field['type'] ?? null) === 'select' && !empty($field['options']) && is_array($field['options'])) {
                $selectOptions[$key] = array_values(array_map('strval', $field['options']));
            }
        }

        $prompt = $this->buildPrompt($generalContext, $currentDetails, $allowedKeys, $requiredKeys, $fieldLabels, $selectOptions);
        $parsed = [];

        try {
            $rawText = $this->callHuggingFace($prompt);
            $parsed = $this->parseJsonResponse($rawText);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Autre IA: fallback local active (runner indisponible).', [
                'error' => $e->getMessage(),
            ]);
        }

        $normalized = $this->normalizeSuggestions($parsed, $allowedKeys, $requiredKeys, $selectOptions, $currentDetails, $generalContext);

        return [
            'correctedText' => $normalized['correctedText'],
            'generatedDescription' => $normalized['generatedDescription'],
            'suggestedGeneral' => $normalized['suggestedGeneral'],
            'suggestedDetails' => $normalized['suggestedDetails'],
            'dynamicFieldPlan' => $normalized['dynamicFieldPlan'],
            'dynamicFieldConfidence' => $normalized['dynamicFieldConfidence'],
            'model' => $this->model,
        ];
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     * @param array<int, string> $priorities
     * @return array<string, mixed>
     */
    public function generateClassificationSuggestion(string $rawText, array $categoryTypes, array $priorities): array
    {
        $normalizedText = trim($rawText);
        if ('' === $normalizedText) {
            throw new \RuntimeException('Ajoutez une description avant de lancer la suggestion intelligente.');
        }

        $prompt = $this->buildClassificationPrompt($normalizedText, $categoryTypes, $priorities);
        $parsed = [];

        try {
            $rawResponse = $this->callHuggingFace($prompt);
            $parsed = $this->parseJsonResponse($rawResponse);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Classification IA: fallback local active (runner indisponible).', [
                'error' => $e->getMessage(),
            ]);
        }

        $normalized = $this->normalizeClassificationSuggestion($parsed, $normalizedText, $categoryTypes, $priorities);
        $normalized['model'] = $this->model;

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function generateDescriptionFromTitle(string $title, ?string $typeDemande = null, ?string $categorie = null): array
    {
        $normalizedTitle = trim($title);
        if ('' === $normalizedTitle) {
            throw new \RuntimeException('Ajoutez un titre avant de lancer la generation de description.');
        }

        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('La cle API Hugging Face est manquante pour la generation de description depuis le titre.');
        }

        $prompt = $this->buildDescriptionFromTitlePrompt($normalizedTitle, $typeDemande, $categorie, null);
        // Keep this path dynamic: slight temperature variation reduces repetitive outputs.
        $temperature = random_int(62, 85) / 100;
        $rawResponse = $this->callHuggingFaceViaHttp($prompt, $temperature, 460);
        $parsed = $this->parseJsonResponse($rawResponse);

        $description = trim((string) ($parsed['description'] ?? ''));
        $description = $this->normalizeGeneratedDescriptionFromTitle($description, $normalizedTitle);

        return [
            'description' => $description,
            'model' => $this->model,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateDescriptionFromTitleAdaptive(
        string $title,
        ?string $typeDemande = null,
        ?string $categorie = null,
        ?int $employeId = null
    ): array {
        $normalizedTitle = trim($title);
        if ('' === $normalizedTitle) {
            throw new \RuntimeException('Ajoutez un titre avant de lancer la generation de description.');
        }

        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('La cle API Hugging Face est manquante pour la generation de description depuis le titre.');
        }

        $prompt = $this->buildDescriptionFromTitlePrompt($normalizedTitle, $typeDemande, $categorie, $employeId);
        $temperature = random_int(62, 88) / 100;
        $rawResponse = $this->callHuggingFaceViaHttp($prompt, $temperature, 480);
        $parsed = $this->parseJsonResponse($rawResponse);

        $description = trim((string) ($parsed['description'] ?? ''));
        $description = $this->normalizeGeneratedDescriptionFromTitle($description, $normalizedTitle);

        return [
            'description' => $description,
            'model' => $this->model,
        ];
    }

    public function recordAcceptedDescriptionFeedback(
        string $title,
        string $description,
        ?string $typeDemande = null,
        ?string $categorie = null,
        ?int $employeId = null
    ): void {
        $normalizedTitle = trim((string) (preg_replace('/\s+/u', ' ', $title) ?? $title));
        $normalizedDescription = trim((string) (preg_replace('/\s+/u', ' ', $description) ?? $description));

        if ('' === $normalizedTitle || '' === $normalizedDescription) {
            return;
        }

        if (strlen($normalizedDescription) < 80) {
            return;
        }

        $path = $this->getDescriptionFeedbackFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->logger->warning('Impossible de creer le dossier de feedback IA.', ['path' => $dir]);
            return;
        }

        $record = [
            'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'employeId' => $employeId,
            'title' => $normalizedTitle,
            'typeDemande' => trim((string) ($typeDemande ?? '')),
            'categorie' => trim((string) ($categorie ?? '')),
            'description' => $normalizedDescription,
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE);
        if (false === $line) {
            return;
        }

        file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        $this->trimDescriptionFeedbackStore($path, 600);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, string>
     */
    public function extractSuggestedDetailsForType(string $rawText, string $typeDemande, array $fields): array
    {
        $text = trim($rawText);
        if ('' === $text || '' === trim($typeDemande) || [] === $fields) {
            return [];
        }

        $normalizedText = strtolower($text);
        $details = [];
        $allDates = $this->extractAllFrenchDates($text);
        $amount = $this->extractAmountFromText($normalizedText);
        $location = $this->firstNonEmpty($this->extractTargetLocation($text), $this->extractCurrentLocation($text));
        $months = $this->extractMonthDuration($normalizedText);

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            $type = trim((string) ($field['type'] ?? 'text'));
            $label = trim((string) ($field['label'] ?? $key));
            $options = isset($field['options']) && is_array($field['options'])
                ? array_values(array_map('strval', $field['options']))
                : [];

            if ('' === $key) {
                continue;
            }

            $value = match ($key) {
                'typeConge' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Conge maladie' => ['maladie', 'malade', 'medical'],
                    'Conge sans solde' => ['sans solde'],
                    'Conge maternite' => ['maternite', 'maternité'],
                    'Conge paternite' => ['paternite', 'paternité'],
                    'Conge exceptionnel' => ['exceptionnel', 'mariage', 'deces', 'décès'],
                    'Conge annuel' => ['conge', 'vacances', 'repos'],
                ]),
                'dateDebut', 'dateDebutTeletravail', 'dateDebutHoraires', 'dateDebutFormation', 'dateSouhaitee', 'dateSouhaiteeFormation', 'datePassage', 'dateHeuresSup', 'dateDepense' => $allDates[0] ?? '',
                'dateFin', 'dateFinTeletravail' => $allDates[1] ?? '',
                'nombreJours' => $this->extractDaysCount($normalizedText, $allDates),
                'motif', 'motifHoraires', 'motifTeletravail', 'motifHeuresSup', 'objectif', 'objectifFormation', 'justification', 'justificationLogiciel', 'justificationCertif', 'descriptionProbleme', 'details' => $text,
                'nombreExemplaires' => $this->extractIntegerNearKeywords($normalizedText, ['exemplaire', 'copie']) ?: '1',
                'motifAttestation' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Banque' => ['banque', 'credit', 'crédit', 'pret', 'prêt'],
                    'Visa' => ['visa', 'consulat', 'ambassade'],
                    'Location immobiliere' => ['location', 'immobiliere', 'immobilier', 'appartement', 'maison'],
                    'Demarches administratives' => ['administrative', 'administratif', 'dossier', 'papier'],
                    'Autre' => ['autre'],
                ]),
                'destinataire' => $this->extractRecipient($text),
                'periode' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Dernier mois' => ['dernier mois', '1 mois'],
                    '3 derniers mois' => ['3 mois', 'trois mois'],
                    '6 derniers mois' => ['6 mois', 'six mois'],
                    'Annee en cours' => ['annee en cours', 'année en cours'],
                    'Annee precedente' => ['annee precedente', 'année precedente', 'année précédente'],
                ]),
                'departementActuel' => $this->extractDepartment($text, true),
                'departementSouhaite' => $this->extractDepartment($text, false),
                'lieuMutation', 'lieuFormation', 'lieuExamen', 'adresseTeletravail' => $location,
                'posteSouhaite' => $this->extractTargetRole($text),
                'preavis' => $this->matchMonthOption($options, $months, $normalizedText, 'Dispense demandee', ['dispense']),
                'montant', 'cout', 'coutCertif' => null !== $amount ? (string) $amount : '',
                'modaliteRemboursement' => $this->matchMonthOption($options, $months, $normalizedText),
                'typeRemboursement' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Frais de transport' => ['transport', 'taxi', 'bus', 'train', 'essence'],
                    'Frais de mission' => ['mission', 'deplacement', 'déplacement'],
                    'Frais de formation' => ['formation', 'certification', 'cours'],
                    'Frais medicaux' => ['medical', 'médical', 'medecin', 'médecin', 'pharmacie'],
                    'Autre' => ['autre'],
                ]),
                'justificatif' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Oui' => ['facture', 'justificatif', 'recu', 'reçu', 'piece jointe', 'pièce jointe'],
                    'Non - a fournir' => ['a fournir', 'à fournir', 'pas encore'],
                ], 'Oui'),
                'typeMateriel' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Fournitures' => ['stylo', 'papier', 'cahier', 'fourniture'],
                    'Mobilier' => ['chaise', 'bureau', 'mobilier'],
                    'Equipement' => ['equipement', 'équipement'],
                    'Autre' => ['autre'],
                ]),
                'descriptionMateriel' => $this->extractObjectDescription($text),
                'quantite', 'nombreHeures' => $this->extractFirstInteger($normalizedText),
                'urgence' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Tres urgente' => ['tres urgent', 'très urgent'],
                    'Urgente' => ['urgent', 'urgence', 'bloquant'],
                    'Normale' => ['normal'],
                ], $options[0] ?? ''),
                'motifBadge' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Badge perdu' => ['badge perdu', 'perdu'],
                    'Badge defectueux' => ['defectueux', 'defectueux', 'abime', 'abîme', 'ne marche pas'],
                    'Extension acces' => ['extension acces', 'nouvel acces', 'zone'],
                    'Nouveau badge' => ['nouveau badge', 'badge'],
                ]),
                'zonesAcces' => $this->extractAccessZones($text),
                'quantiteCarte' => $this->matchClosestNumericOption($options, $this->extractFirstInteger($normalizedText)),
                'titreFonction' => $this->extractTargetRole($text),
                'telephone' => $this->extractPhoneNumber($text),
                'email' => $this->extractEmailAddress($text),
                'typeMaterielInfo' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Ordinateur portable' => ['portable', 'laptop'],
                    'Ordinateur fixe' => ['fixe', 'desktop'],
                    'Ecran' => ['ecran', 'écran', 'moniteur'],
                    'Clavier/Souris' => ['clavier', 'souris'],
                    'Casque' => ['casque', 'headset'],
                    'Webcam' => ['webcam', 'camera'],
                    'Autre' => ['autre'],
                ]),
                'motifMateriel' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Remplacement' => ['remplacement', 'remplacer'],
                    'Mise a niveau' => ['mise a niveau', 'upgrade', 'amelioration', 'amélioration'],
                    'Nouveau besoin' => ['nouveau', 'besoin'],
                ]),
                'specifications' => $text,
                'systeme', 'nomLogiciel' => $this->extractSystemName($text),
                'typeAcces' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Administrateur' => ['admin', 'administrateur'],
                    'Lecture/Ecriture' => ['ecriture', 'écriture', 'modifier', 'edition', 'édition'],
                    'Lecture seule' => ['lecture seule', 'consultation', 'voir'],
                ]),
                'version' => $this->extractVersion($text),
                'typeLicence' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Abonnement mensuel' => ['mensuel', 'mois'],
                    'Abonnement annuel' => ['annuel', 'an'],
                    'Open source' => ['open source', 'gratuit'],
                    'Achat' => ['achat', 'acheter'],
                ]),
                'typeProbleme' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Materiel' => ['materiel', 'matériel', 'pc', 'ordinateur', 'ecran'],
                    'Logiciel' => ['logiciel', 'application', 'software'],
                    'Reseau' => ['reseau', 'réseau', 'wifi', 'internet'],
                    'Email' => ['email', 'mail', 'outlook'],
                    'Imprimante' => ['imprimante'],
                    'Autre' => ['autre'],
                ]),
                'impact' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Bloquant' => ['bloquant', 'impossible de travailler'],
                    'Important' => ['important', 'urgent'],
                    'Modere' => ['modere', 'modéré'],
                    'Faible' => ['faible', 'leger', 'léger'],
                ]),
                'nomFormation', 'nomFormationExt', 'nomCertification' => $this->extractTrainingName($text),
                'formateur', 'organisme', 'organismeCertif' => $this->extractOrganization($text),
                'duree', 'dureeChangement' => $this->extractDurationLabel($options, $normalizedText),
                'typeTeletravail' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Teletravail regulier' => ['regulier', 'régulier', 'chaque semaine'],
                    'Teletravail occasionnel' => ['occasionnel'],
                    'Teletravail exceptionnel' => ['exceptionnel', 'urgent'],
                ]),
                'joursParSemaine' => $this->matchDaysPerWeekOption($options, $normalizedText),
                'joursSouhaites' => $this->extractWeekDays($normalizedText),
                'horairesActuels', 'horairesSouhaites', 'heureDebut', 'heureFin' => $this->extractTimeWindow($normalizedText, $key),
                'valideParResponsable' => $this->matchOptionByKeywords($options, $normalizedText, [
                    'Oui' => ['valide', 'validé', 'accord responsable', 'approuve'],
                    'En attente de validation' => ['attente', 'pas encore'],
                ], $options[1] ?? ($options[0] ?? '')),
                default => $this->inferGenericFieldValue($key, $type, $label, $options, $text, $normalizedText, $allDates, $amount, $location),
            };

            $value = trim((string) $value);
            if ('' !== $value) {
                $details[$key] = $value;
            }
        }

        return $details;
    }

    private function buildPrompt(
        array $generalContext,
        array $currentDetails,
        array $allowedKeys,
        array $requiredKeys,
        array $fieldLabels,
        array $selectOptions
    ): string {
        $context = [
            'categorie' => (string) ($generalContext['categorie'] ?? ''),
            'typeDemande' => (string) ($generalContext['typeDemande'] ?? 'Autre'),
            'titre' => (string) ($generalContext['titre'] ?? ''),
            'descriptionGenerale' => (string) ($generalContext['description'] ?? ''),
            'priorite' => (string) ($generalContext['priorite'] ?? ''),
            'userPromptAutre' => (string) ($generalContext['aiDescriptionPrompt'] ?? ''),
            'detailsActuels' => $currentDetails,
        ];

        $schemaExample = [
            'descriptionBesoin' => 'Texte detaille et concret de la demande',
            'besoinPersonnalise' => 'Titre court specifique a la demande',
            'niveauUrgenceAutre' => $selectOptions['niveauUrgenceAutre'][1] ?? 'Normale',
            'dateSouhaiteeAutre' => '',
            'pieceOuContexte' => 'Contexte, contraintes ou references utiles',
        ];

        $rootSchemaExample = [
            'correctedText' => 'Je souhaite une avance sur salaire pour une depense imprvue ce mois-ci.',
            'general' => [
                'titre' => 'Avance sur salaire exceptionnelle',
                'description' => 'Le collaborateur demande une avance sur salaire pour couvrir une depense urgente.',
                'priorite' => 'NORMALE',
            ],
            'details' => $schemaExample,
            'remove_fields' => ['dateSouhaiteeAutre'],
            'custom_fields' => [
                [
                    'key' => 'montantSouhaite',
                    'label' => 'Montant souhaite (TND)',
                    'type' => 'number',
                    'required' => true,
                    'value' => '1200',
                    'options' => [],
                ],
            ],
            'replace_base' => false,
        ];

        $optionalFieldKeys = [];
        foreach ($allowedKeys as $key) {
            if (empty($requiredKeys[$key])) {
                $optionalFieldKeys[] = $key;
            }
        }

        return "Tu es un assistant RH/IT qui aide a rediger des demandes internes professionnelles en francais.\n"
            . "Renvoie STRICTEMENT un JSON valide, sans markdown, sans texte avant/apres.\n"
            . "Tu dois renvoyer un objet JSON racine avec les cles: correctedText, general, details, remove_fields, custom_fields, replace_base.\n"
            . "Les cles details autorisees sont: " . json_encode(array_values($allowedKeys), JSON_UNESCAPED_UNICODE) . "\n"
            . "Cles details obligatoires: " . json_encode(array_values(array_filter($allowedKeys, fn($k) => !empty($requiredKeys[$k]))), JSON_UNESCAPED_UNICODE) . "\n"
            . "Cles details optionnelles supprimables: " . json_encode($optionalFieldKeys, JSON_UNESCAPED_UNICODE) . "\n"
            . "Contraintes:\n"
            . "- correctedText: correction orthographique et grammaticale du userPromptAutre, ton professionnel, sens conserve.\n"
            . "- general.titre: phrase courte, professionnelle et precise.\n"
            . "- general.description: resume complet de la demande (2 a 5 lignes).\n"
            . "- general.priorite: uniquement HAUTE, NORMALE ou BASSE.\n"
            . "- descriptionBesoin: texte professionnel, clair, concis, actionnable.\n"
            . "- besoinPersonnalise: intitule court.\n"
            . "- niveauUrgenceAutre: une valeur parmi " . json_encode($selectOptions['niveauUrgenceAutre'] ?? ['Faible', 'Normale', 'Urgente', 'Tres urgente'], JSON_UNESCAPED_UNICODE) . "\n"
            . "- dateSouhaiteeAutre: format YYYY-MM-DD ou chaine vide.\n"
            . "- pieceOuContexte: details complementaires utiles.\n"
            . "- remove_fields: tableau de cles optionnelles inutiles (jamais de champ obligatoire).\n"
            . "- custom_fields: 0 a 8 champs supplementaires utiles selon le texte employe.\n"
            . "- Evite les champs generiques vagues (ex: objet/details) si aucune valeur metier concrete n est detectable.\n"
            . "- Chaque custom_field: key, label, type(text|textarea|select|number|date), required(boolean), value(string), options(array string pour select).\n"
            . "- replace_base: booleen. false par defaut. Mets true uniquement si custom_fields couvre mieux le besoin et contient au moins 2 champs metier solides.\n"
            . "Libelles metiers des champs: " . json_encode($fieldLabels, JSON_UNESCAPED_UNICODE) . "\n"
            . "Contexte utilisateur: " . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n"
            . "Exemple de forme attendue: " . json_encode($rootSchemaExample, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     * @param array<int, string> $priorities
     */
    private function buildClassificationPrompt(string $rawText, array $categoryTypes, array $priorities): string
    {
        $availableCategories = array_values(array_keys($categoryTypes));
        $typeMap = [];

        foreach ($categoryTypes as $category => $types) {
            $typeMap[(string) $category] = array_values(array_map('strval', $types));
        }

        $example = [
            'correctedText' => 'Je souhaite obtenir un acces a Salesforce pour mon nouveau poste.',
            'categorie' => 'Informatique',
            'typeDemande' => 'Acces systeme',
            'priorite' => 'NORMALE',
            'titre' => 'Demande d acces a Salesforce',
            'description' => 'Le collaborateur demande un acces a Salesforce dans le cadre de sa nouvelle prise de poste.',
            'confidence' => 0.93,
        ];

        return "Tu es un assistant de classification de demandes internes en francais.\n"
            . "Ta mission:\n"
            . "1. Corriger l orthographe, la grammaire et les fautes de frappe du texte utilisateur.\n"
            . "2. Conserver le sens metier du besoin.\n"
            . "3. Choisir la meilleure categorie, le meilleur type de demande et la priorite la plus adaptee.\n"
            . "4. Produire un titre court et une description propre pour un stockage fiable.\n"
            . "Renvoie STRICTEMENT un JSON valide, sans markdown.\n"
            . "Clés obligatoires: correctedText, categorie, typeDemande, priorite, titre, description, confidence.\n"
            . "Categories autorisees: " . json_encode($availableCategories, JSON_UNESCAPED_UNICODE) . "\n"
            . "Types autorises par categorie: " . json_encode($typeMap, JSON_UNESCAPED_UNICODE) . "\n"
            . "Priorites autorisees: " . json_encode(array_values($priorities), JSON_UNESCAPED_UNICODE) . "\n"
            . "Regles:\n"
            . "- correctedText: texte corrige, naturel et professionnel.\n"
            . "- categorie: une seule categorie autorisee.\n"
            . "- typeDemande: un seul type appartenant a la categorie choisie.\n"
            . "- priorite: uniquement une valeur autorisee.\n"
            . "- titre: court, clair, exploitable en base.\n"
            . "- description: 1 a 3 phrases propres, sans inventer des details absents.\n"
            . "- confidence: nombre entre 0 et 1.\n"
            . "Texte utilisateur brut: " . json_encode($rawText, JSON_UNESCAPED_UNICODE) . "\n"
            . "Exemple attendu: " . json_encode($example, JSON_UNESCAPED_UNICODE);
    }

    private function buildDescriptionFromTitlePrompt(string $title, ?string $typeDemande, ?string $categorie, ?int $employeId): string
    {
        $example = [
            'description' => 'Bonjour, je souhaite soumettre une demande de conge annuel pour raison de sante. Cette demande me permettra de gerer ma situation personnelle dans de bonnes conditions tout en restant organise vis-a-vis de l equipe. Je reste disponible pour fournir toute precision utile et je vous remercie par avance pour votre traitement.',
        ];

        $adaptiveContext = $this->buildAdaptiveDescriptionContext($typeDemande, $categorie, $employeId);

        return "Tu aides a rediger des descriptions professionnelles de demandes internes en francais.\n"
            . "A partir d un titre, genere une description developpee, claire, naturelle, polie et exploitable directement dans un formulaire RH/IT.\n"
            . "Renvoie STRICTEMENT un JSON valide avec une seule cle: description.\n"
            . "Contraintes:\n"
            . "- 3 a 5 phrases.\n"
            . "- 65 a 120 mots environ.\n"
            . "- Ecris a la premiere personne (je).\n"
            . "- Commence par 'Bonjour,'.\n"
            . "- Inclure explicitement le besoin principal mentionne dans le titre.\n"
            . "- Inclure une phrase de contexte utile et une phrase de politesse/conclusion.\n"
            . "- Ton professionnel, simple et courtois.\n"
            . "- Ne pas inventer de dates, montants ou details precis absents du titre.\n"
            . "- Eviter les formulations vagues (ex: demande concernant...).\n"
            . "- Si le type ou la categorie sont fournis, reste coherent avec eux.\n"
            . "- Ne pas copier mot a mot les exemples de style; inspire-toi seulement de leur ton et structure.\n"
            . "Titre: " . json_encode($title, JSON_UNESCAPED_UNICODE) . "\n"
            . "Type de demande: " . json_encode((string) ($typeDemande ?? ''), JSON_UNESCAPED_UNICODE) . "\n"
            . "Categorie: " . json_encode((string) ($categorie ?? ''), JSON_UNESCAPED_UNICODE) . "\n"
            . "Contexte adaptatif appris: " . json_encode($adaptiveContext, JSON_UNESCAPED_UNICODE) . "\n"
            . "Exemple attendu: " . json_encode($example, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAdaptiveDescriptionContext(?string $typeDemande, ?string $categorie, ?int $employeId): array
    {
        $samples = $this->loadDescriptionFeedbackSamples();
        if ([] === $samples) {
            return [
                'sampleCount' => 0,
                'advice' => 'Aucun historique valide pour le moment. Utiliser un style professionnel standard.',
            ];
        }

        $normalizedType = $this->normalizeLooseLabel((string) ($typeDemande ?? ''));
        $normalizedCategorie = $this->normalizeLooseLabel((string) ($categorie ?? ''));

        $ranked = [];
        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }

            $score = 0;
            $sampleEmploye = isset($sample['employeId']) && is_numeric((string) $sample['employeId'])
                ? (int) $sample['employeId']
                : null;

            if (null !== $employeId && null !== $sampleEmploye && $employeId === $sampleEmploye) {
                $score += 5;
            }

            $sampleType = $this->normalizeLooseLabel((string) ($sample['typeDemande'] ?? ''));
            if ('' !== $normalizedType && '' !== $sampleType && $normalizedType === $sampleType) {
                $score += 3;
            }

            $sampleCategorie = $this->normalizeLooseLabel((string) ($sample['categorie'] ?? ''));
            if ('' !== $normalizedCategorie && '' !== $sampleCategorie && $normalizedCategorie === $sampleCategorie) {
                $score += 2;
            }

            $ranked[] = ['score' => $score, 'sample' => $sample];
        }

        usort($ranked, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']));

        $picked = [];
        foreach ($ranked as $item) {
            $description = trim((string) (($item['sample']['description'] ?? '')));
            if ('' === $description) {
                continue;
            }

            $picked[] = [
                'score' => (int) ($item['score'] ?? 0),
                'typeDemande' => (string) (($item['sample']['typeDemande'] ?? '')),
                'categorie' => (string) (($item['sample']['categorie'] ?? '')),
                'description' => $this->truncateText($description, 260),
            ];

            if (count($picked) >= 4) {
                break;
            }
        }

        $wordCounts = [];
        foreach ($picked as $sample) {
            $wordCounts[] = str_word_count((string) ($sample['description'] ?? ''));
        }

        $avgWords = [] !== $wordCounts ? (int) round(array_sum($wordCounts) / count($wordCounts)) : 0;

        return [
            'sampleCount' => count($samples),
            'targetedSampleCount' => count($picked),
            'avgWordsFromSamples' => $avgWords,
            'styleExamples' => $picked,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadDescriptionFeedbackSamples(): array
    {
        $path = $this->getDescriptionFeedbackFilePath();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw) || [] === $raw) {
            return [];
        }

        $samples = [];
        foreach (array_slice($raw, -600) as $line) {
            $decoded = json_decode((string) $line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $description = trim((string) ($decoded['description'] ?? ''));
            $title = trim((string) ($decoded['title'] ?? ''));
            if ('' === $description || '' === $title) {
                continue;
            }

            $decoded['description'] = $description;
            $decoded['title'] = $title;
            $samples[] = $decoded;
        }

        return $samples;
    }

    private function trimDescriptionFeedbackStore(string $path, int $maxLines): void
    {
        if ($maxLines < 100 || !is_file($path)) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || count($lines) <= $maxLines) {
            return;
        }

        $trimmed = array_slice($lines, -$maxLines);
        @file_put_contents($path, implode(PHP_EOL, $trimmed) . PHP_EOL, LOCK_EX);
    }

    private function getDescriptionFeedbackFilePath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ai' . DIRECTORY_SEPARATOR . 'description_feedback.jsonl';
    }

    private function normalizeLooseLabel(string $value): string
    {
        $normalized = trim((string) (preg_replace('/\s+/u', ' ', $value) ?? $value));
        $normalized = strtolower($normalized);
        if (!function_exists('iconv')) {
            return $normalized;
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        return false === $ascii ? $normalized : strtolower($ascii);
    }

    private function truncateText(string $text, int $maxLength): string
    {
        $clean = trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
        if (strlen($clean) <= $maxLength) {
            return $clean;
        }

        return rtrim(substr($clean, 0, max(0, $maxLength - 3))) . '...';
    }

    private function normalizeGeneratedDescriptionFromTitle(string $description, string $title): string
    {
        $clean = trim((string) (preg_replace('/\s+/u', ' ', $description) ?? $description));

        if ('' === $clean) {
            return 'Bonjour, je souhaite soumettre une demande liee a "' . $title . '". Cette demande correspond a un besoin concret que je souhaite traiter de maniere claire et conforme aux procedures internes. Je reste disponible pour tout complement d information et je vous remercie par avance pour votre retour.';
        }

        $lower = strtolower($clean);
        if (!str_starts_with($lower, 'bonjour')) {
            $clean = 'Bonjour, ' . ltrim($clean, ',;:. ');
        }

        if (!preg_match('/[.!?]$/', $clean)) {
            $clean .= '.';
        }

        if (strlen($clean) < 120) {
            $clean .= ' Cette demande est importante pour assurer une bonne organisation de mon activite et faciliter son traitement administratif. Je reste disponible pour fournir tout complement utile si necessaire.';
        }

        return $clean;
    }

    private function callHuggingFace(string $prompt): string
    {
        if (!$this->canUsePythonRunner()) {
            throw new \RuntimeException('Le modele IA local est indisponible: script Python introuvable.');
        }

        return $this->callHuggingFaceViaPython($prompt);
    }

    private function canUsePythonRunner(): bool
    {
        $scriptPath = trim($this->pythonScriptPath);
        return '' !== $scriptPath && is_file($scriptPath);
    }

    private function callHuggingFaceViaPython(string $prompt): string
    {
        $payload = [
            'prompt' => $prompt,
            'timeoutSeconds' => $this->timeoutSeconds,
            'temperature' => 0.2,
            'maxTokens' => 420,
            'trainingSamples' => $this->fetchClassificationTrainingSamples(),
        ];

        $lastError = '';
        foreach ($this->getPythonCommandCandidates() as $commandPrefix) {
            try {
                $process = new Process(array_merge($commandPrefix, [$this->pythonScriptPath]));
                $process->setInput((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
                $process->setTimeout($this->timeoutSeconds + 5);
                $process->run();

                if (!$process->isSuccessful()) {
                    $lastError = trim($process->getErrorOutput() ?: $process->getOutput());
                    continue;
                }

                $stdout = trim($process->getOutput());
                $decoded = json_decode($stdout, true);
                if (!is_array($decoded)) {
                    $lastError = 'Python AI runner returned invalid JSON.';
                    continue;
                }

                if (!($decoded['ok'] ?? false)) {
                    $lastError = (string) ($decoded['error'] ?? 'Unknown Python AI runner error.');
                    continue;
                }

                $text = trim((string) ($decoded['text'] ?? ''));
                if ('' === $text) {
                    $lastError = 'Python AI runner returned an empty text response.';
                    continue;
                }

                return $text;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $commands = array_map(static fn(array $cmd): string => implode(' ', $cmd), $this->getPythonCommandCandidates());
        throw new \RuntimeException(
            'Le moteur IA local est indisponible: aucune commande Python valide. ' .
            'Commandes testees: ' . implode(', ', $commands) . '. ' .
            'Configurez ml.python_executable ou installez Python dans le PATH. ' .
            ('' !== $lastError ? ('Details: ' . $lastError) : '')
        );
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function getPythonCommandCandidates(): array
    {
        $candidates = [];
        $configured = trim($this->pythonExecutable);

        if ('' !== $configured) {
            $candidates[] = [$configured];
        }

        if ('\\' === DIRECTORY_SEPARATOR) {
            $candidates[] = ['py', '-3'];
            $candidates[] = ['py'];
            $candidates[] = ['python3'];
            $candidates[] = ['python'];
        } else {
            $candidates[] = ['python3'];
            $candidates[] = ['python'];
        }

        $unique = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = implode("\0", $candidate);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function fetchClassificationTrainingSamples(): array
    {
        try {
            return $this->demandeRepository->fetchClassificationTrainingSamples(1200);
        } catch (\Throwable $e) {
            $this->logger->warning('Impossible de charger les echantillons de training classification.', [
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function callHuggingFaceViaHttp(string $prompt, float $temperature = 0.2, int $maxTokens = 420): string
    {
        try {
            $model = trim($this->model);
            if ('' === $model) {
                throw new \RuntimeException('Le modele Hugging Face est manquant. Configurez HUGGINGFACE_MODEL.');
            }

            $response = $this->httpClient->request('POST', 'https://router.huggingface.co/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => max(0.0, min(1.0, $temperature)),
                    'max_tokens' => max(120, $maxTokens),
                    'stream' => false,
                ],
                'timeout' => $this->timeoutSeconds,
            ]);

            /** @var mixed $payload */
            $payload = $response->toArray(false);

            if (isset($payload['error']) && is_string($payload['error'])) {
                throw new \RuntimeException('Hugging Face API error: ' . $payload['error']);
            }

            if (isset($payload['message']) && is_string($payload['message'])) {
                throw new \RuntimeException('Hugging Face API error: ' . $payload['message']);
            }

            if (isset($payload['detail']) && is_string($payload['detail'])) {
                throw new \RuntimeException('Hugging Face API error: ' . $payload['detail']);
            }

            if (isset($payload['generated_text']) && is_string($payload['generated_text'])) {
                return $payload['generated_text'];
            }

            if (is_array($payload) && isset($payload[0]['generated_text']) && is_string($payload[0]['generated_text'])) {
                return $payload[0]['generated_text'];
            }

            if (
                isset($payload['choices']) &&
                is_array($payload['choices']) &&
                isset($payload['choices'][0]['message']['content']) &&
                is_string($payload['choices'][0]['message']['content'])
            ) {
                return $payload['choices'][0]['message']['content'];
            }

            return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';
        } catch (\RuntimeException $e) {
            $rawMessage = $e->getMessage();
            $this->logger->error('Erreur Hugging Face description', ['exception' => $rawMessage]);
            throw new \RuntimeException($this->toUserFriendlyMessage($rawMessage));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Erreur transport Hugging Face description', ['exception' => $e->getMessage()]);
            throw new \RuntimeException('Le service IA de description est temporairement indisponible.');
        } catch (\Throwable $e) {
            $this->logger->error('Erreur Hugging Face description', ['exception' => $e->getMessage()]);
            throw new \RuntimeException('Erreur IA Hugging Face: ' . $e->getMessage());
        }
    }

    private function toUserFriendlyMessage(string $rawMessage): string
    {
        $normalized = strtolower($rawMessage);

        if (str_contains($normalized, 'sufficient permissions to call inference providers')) {
            return 'Votre token Hugging Face n a pas la permission Inference Providers. Activez cette permission dans les parametres du token puis reessayez.';
        }

        if (str_contains($normalized, 'unauthorized') || str_contains($normalized, '401')) {
            return 'Token Hugging Face invalide ou non autorise. Verifiez HUGGINGFACE_API_KEY.';
        }

        if (str_contains($normalized, 'rate limit')) {
            return 'Limite Hugging Face atteinte. Patientez quelques instants puis reessayez.';
        }

        return $rawMessage;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $rawText): array
    {
        $clean = trim($rawText);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $clean) ?? $clean;
        }

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $clean, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<int, string> $allowedKeys
     * @param array<string, array<int, string>> $selectOptions
     * @param array<string, mixed> $currentDetails
     * @param array<string, mixed> $generalContext
     * @return array<string, mixed>
     */
    private function normalizeSuggestions(
        array $parsed,
        array $allowedKeys,
        array $requiredKeys,
        array $selectOptions,
        array $currentDetails,
        array $generalContext
    ): array {
        $generalPayload = isset($parsed['general']) && is_array($parsed['general']) ? $parsed['general'] : [];
        $detailsPayload = isset($parsed['details']) && is_array($parsed['details']) ? $parsed['details'] : $parsed;
        $removePayload = isset($parsed['remove_fields']) && is_array($parsed['remove_fields']) ? $parsed['remove_fields'] : [];
        $customPayload = isset($parsed['custom_fields']) && is_array($parsed['custom_fields']) ? $parsed['custom_fields'] : [];
        $replaceBasePayload = $this->toBooleanFlag($parsed['replace_base'] ?? false);
        $correctedTextPayload = trim((string) ($parsed['correctedText'] ?? ''));

        $details = [];

        foreach ($allowedKeys as $key) {
            if (isset($detailsPayload[$key])) {
                $details[$key] = $this->normalizeValue((string) $key, $detailsPayload[$key], $selectOptions[$key] ?? []);
            }
        }

        foreach ($allowedKeys as $key) {
            if (!isset($details[$key]) && isset($currentDetails[$key]) && '' !== trim((string) $currentDetails[$key])) {
                $details[$key] = (string) $currentDetails[$key];
            }
        }

        $generatedDescription = trim((string) ($details['descriptionBesoin'] ?? ''));
        if ('' === $generatedDescription) {
            $generatedDescription = $this->firstNonEmpty(
                trim((string) ($generalPayload['description'] ?? '')),
                trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                trim((string) ($generalContext['description'] ?? ''))
            );
        } elseif ('' !== trim((string) ($generalContext['aiDescriptionPrompt'] ?? ''))) {
            // When user rewrites the prompt and regenerates, prioritize latest prompt over stale previous detail text.
            $generatedDescription = trim((string) ($generalContext['aiDescriptionPrompt'] ?? ''));
        }

        if ('' === trim((string) ($details['besoinPersonnalise'] ?? ''))) {
            $details['besoinPersonnalise'] = trim((string) ($generalContext['titre'] ?? 'Demande personnalisee'));
        }

        if (isset($details['niveauUrgenceAutre']) && isset($selectOptions['niveauUrgenceAutre'])) {
            if (!in_array($details['niveauUrgenceAutre'], $selectOptions['niveauUrgenceAutre'], true)) {
                $details['niveauUrgenceAutre'] = $selectOptions['niveauUrgenceAutre'][1] ?? $selectOptions['niveauUrgenceAutre'][0] ?? 'Normale';
            }
        }

        if (isset($details['dateSouhaiteeAutre']) && '' !== $details['dateSouhaiteeAutre']) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $details['dateSouhaiteeAutre'])) {
                $details['dateSouhaiteeAutre'] = '';
            }
        }

        if (!isset($details['descriptionBesoin']) || '' === trim((string) $details['descriptionBesoin'])) {
            $details['descriptionBesoin'] = $generatedDescription;
        }

        $details['pieceOuContexte'] = '';

        $priorite = strtoupper(trim((string) ($generalPayload['priorite'] ?? $generalContext['priorite'] ?? 'NORMALE')));
        if (!in_array($priorite, ['HAUTE', 'NORMALE', 'BASSE'], true)) {
            $priorite = 'NORMALE';
        }

        $suggestedGeneral = [
            'titre' => $this->firstNonEmpty(
                trim((string) ($generalPayload['titre'] ?? '')),
                trim((string) ($details['besoinPersonnalise'] ?? '')),
                trim((string) ($generalContext['titre'] ?? '')),
                'Demande personnalisee'
            ),
            'description' => $this->firstNonEmpty(
                trim((string) ($generalPayload['description'] ?? '')),
                trim((string) ($details['descriptionBesoin'] ?? '')),
                trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                trim((string) ($generalContext['description'] ?? ''))
            ),
            'priorite' => $priorite,
            'categorie' => $this->firstNonEmpty(
                $this->inferCategoryFromDescription($generalContext),
                trim((string) ($generalContext['categorie'] ?? '')),
                'Autre'
            ),
        ];

        $optionalAllowedToRemove = [];
        foreach ($allowedKeys as $baseKey) {
            if (empty($requiredKeys[$baseKey])) {
                $optionalAllowedToRemove[] = $baseKey;
            }
        }

        $removeFields = [];
        foreach ($removePayload as $removeKeyRaw) {
            $removeKey = trim((string) $removeKeyRaw);
            if (in_array($removeKey, $optionalAllowedToRemove, true)) {
                $removeFields[$removeKey] = true;
            }
        }

        $customFields = $this->normalizeCustomFields($customPayload, array_merge($allowedKeys, array_keys($removeFields)));
        $inferredCustomFields = $this->inferCustomFieldsFromDescription($generalContext, $details);
        // Keep deterministic keyword-based inference first for Autre to avoid noisy LLM extras.
        $customFields = $this->mergeCustomFields($inferredCustomFields, $customFields);

        $inferredUrgence = $this->inferUrgenceFromDescription($generalContext);
        if (isset($selectOptions['niveauUrgenceAutre']) && in_array($inferredUrgence, $selectOptions['niveauUrgenceAutre'], true)) {
            $details['niveauUrgenceAutre'] = $inferredUrgence;
        }

        $inferredDate = $this->extractFrenchDate($generalContext);
        if ('' !== $inferredDate) {
            $details['dateSouhaiteeAutre'] = $inferredDate;
        }

        $defaultTitle = $this->inferDefaultTitle($generalContext);
        if ('' === trim((string) ($details['besoinPersonnalise'] ?? ''))) {
            $details['besoinPersonnalise'] = $defaultTitle;
        }

        if ('' === trim((string) ($details['descriptionBesoin'] ?? ''))) {
            $details['descriptionBesoin'] = $this->firstNonEmpty(
                trim((string) ($generalPayload['description'] ?? '')),
                trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                trim((string) ($generalContext['description'] ?? ''))
            );
        }

        $correctedText = $this->firstNonEmpty(
            $correctedTextPayload,
            trim((string) ($generalPayload['description'] ?? '')),
            trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
            trim((string) ($details['descriptionBesoin'] ?? ''))
        );
        $correctedText = trim((string) (preg_replace('/\s+/u', ' ', $correctedText) ?? $correctedText));

        $replaceBase = $this->shouldReplaceBaseFields($replaceBasePayload, $customFields);
        $dynamicFieldConfidence = $this->buildAutrePlanConfidence(
            $customFields,
            array_values(array_keys($removeFields)),
            $replaceBase,
            $correctedText,
            $generatedDescription
        );

        return [
            'correctedText' => $correctedText,
            'generatedDescription' => $generatedDescription,
            'suggestedGeneral' => $suggestedGeneral,
            'suggestedDetails' => $details,
            'dynamicFieldPlan' => [
                'add' => $customFields,
                'remove' => array_values(array_keys($removeFields)),
                'replaceBase' => $replaceBase,
            ],
            'dynamicFieldConfidence' => $dynamicFieldConfidence,
        ];
    }

    /**
     * @param array<string, mixed> $generalContext
     * @param array<string, string> $details
     * @return array<int, array<string, mixed>>
     */
    private function inferCustomFieldsFromDescription(array $generalContext, array $details): array
    {
        $rawText = $this->buildInferenceText($generalContext);
        $text = strtolower($rawText);
        $customFields = [];

        $intentScores = $this->computePromptIntentScores($rawText);
        $dominantIntent = $this->resolveDominantIntent($intentScores);

        $amount = $this->extractAmountFromText($text);
        $date = $this->extractFrenchDate($generalContext);
        $routeLocations = $this->extractRouteLocations($rawText);
        $currentLocation = $this->firstNonEmpty($routeLocations['from'], $this->extractCurrentLocation($rawText));
        $targetLocation = $this->firstNonEmpty($routeLocations['to'], $this->extractTargetLocation($rawText));

        $hasClearTransportRoute = '' !== $currentLocation || '' !== $targetLocation;

        $formationType = $this->inferFormationType($rawText);
        $formationName = $this->firstNonEmpty(
            $this->extractKnownFormationKeywordTopic($rawText),
            $this->extractTrainingName($rawText),
            $this->extractFormationTopic($rawText)
        );

        if ('' === $formationType && '' !== $formationName) {
            $formationType = $this->inferDefaultFormationType($rawText, $formationName);
        }

        if ('' !== $formationName && $this->isLikelyLocationCandidate($formationName)) {
            $formationName = '';
        }

        $hasParkingContext = ($intentScores['parking'] ?? 0) >= 2;
        $hasWorkplaceContext = ($intentScores['workplace'] ?? 0) >= 2;
        $hasFormationContext = (($intentScores['formation'] ?? 0) >= 2 || '' !== $formationType || '' !== $formationName)
            && 'parking' !== $dominantIntent;
        $hasTransportContext = ($intentScores['transport'] ?? 0) >= 2
            && 'parking' !== $dominantIntent;

        if ($hasFormationContext || $hasTransportContext) {
            if ($hasFormationContext) {
                $customFields[] = [
                    'key' => 'ai_type_formation',
                    'label' => 'Type de formation',
                    'type' => 'select',
                    'required' => true,
                    'value' => '' !== $formationType ? $formationType : 'Autre',
                    'options' => ['Formation interne', 'Formation externe', 'Certification', 'Autre'],
                ];

                $customFields[] = [
                    'key' => 'ai_nom_formation',
                    'label' => 'Nom de la formation',
                    'type' => 'text',
                    'required' => true,
                    'value' => $formationName,
                ];
            }

            $customFields[] = [
                'key' => 'ai_lieu_depart_actuel',
                'label' => 'Lieu de depart actuel',
                'type' => 'text',
                'required' => true,
                'value' => $currentLocation,
            ];

            $customFields[] = [
                'key' => 'ai_lieu_souhaite',
                'label' => 'Lieu souhaite',
                'type' => 'text',
                'required' => true,
                'value' => $targetLocation,
            ];

            if ($hasTransportContext || $this->containsWord($rawText, 'moyen de transport')) {
                $customFields[] = [
                    'key' => 'ai_type_transport',
                    'label' => 'Type de transport souhaite',
                    'type' => 'select',
                    'required' => false,
                    'value' => 'A definir',
                    'options' => ['A definir', 'Bus', 'Train', 'Voiture de service', 'Taxi'],
                ];
            }
        }

        if (str_contains($text, 'avance') && str_contains($text, 'salaire')) {
            $customFields[] = [
                'key' => 'ai_type_besoin',
                'label' => 'Type de besoin financier',
                'type' => 'select',
                'required' => true,
                'value' => 'Avance sur salaire',
                'options' => ['Avance sur salaire', 'Aide exceptionnelle', 'Pret interne'],
            ];

            $customFields[] = [
                'key' => 'ai_motif_financier',
                'label' => 'Motif financier',
                'type' => 'textarea',
                'required' => true,
                'value' => $this->firstNonEmpty(
                    trim((string) ($details['descriptionBesoin'] ?? '')),
                    trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                    trim((string) ($generalContext['description'] ?? ''))
                ),
            ];
        }

        if ($hasParkingContext) {
            $parkingType = 'Place reservee';
            if ($this->containsAnyWord($rawText, ['autorisation temporaire', 'temporaire'])) {
                $parkingType = 'Autorisation temporaire';
            } elseif ($this->containsAnyWord($rawText, ['acces parking', 'badge parking', 'acces'])) {
                $parkingType = 'Acces parking';
            }

            $customFields[] = [
                'key' => 'ai_type_stationnement',
                'label' => 'Type de demande parking',
                'type' => 'select',
                'required' => true,
                'value' => $parkingType,
                'options' => ['Place reservee', 'Acces parking', 'Autorisation temporaire', 'Autre'],
            ];

            $customFields[] = [
                'key' => 'ai_zone_souhaitee',
                'label' => 'Zone ou emplacement souhaite',
                'type' => 'text',
                'required' => true,
                'value' => $this->extractParkingZone($rawText),
            ];

            $customFields[] = [
                'key' => 'ai_horaire_arrivee',
                'label' => 'Horaire habituel d arrivee',
                'type' => 'text',
                'required' => false,
                'value' => $this->extractPreferredArrivalTime($rawText),
            ];

            $customFields[] = [
                'key' => 'ai_justification_stationnement',
                'label' => 'Justification du besoin parking',
                'type' => 'textarea',
                'required' => true,
                'value' => $this->firstNonEmpty(
                    trim((string) ($details['descriptionBesoin'] ?? '')),
                    $this->extractPrimaryIntentFromPrompt((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                    trim((string) ($generalContext['description'] ?? ''))
                ),
            ];
        }

        if ($hasWorkplaceContext && !$hasFormationContext && !$hasTransportContext) {
            $workplaceType = $this->inferWorkplaceNeedType($rawText);
            $workplaceLocation = $this->extractWorkplaceLocationHint($rawText);
            $workplacePriority = $this->inferWorkplacePriority($rawText);

            $customFields[] = [
                'key' => 'ai_type_besoin_workplace',
                'label' => 'Type de besoin logistique',
                'type' => 'select',
                'required' => true,
                'value' => $workplaceType,
                'options' => ['Casier securise', 'Espace calme', 'Ajustement eclairage', 'Autorisation interne', 'Amenagement poste', 'Autre'],
            ];

            $customFields[] = [
                'key' => 'ai_objet_besoin_workplace',
                'label' => 'Objet de la demande',
                'type' => 'text',
                'required' => true,
                'value' => $this->firstNonEmpty(
                    $this->extractPrimaryIntentFromPrompt((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                    trim((string) ($details['descriptionBesoin'] ?? '')),
                    trim((string) ($generalContext['description'] ?? ''))
                ),
            ];

            $customFields[] = [
                'key' => 'ai_justification_workplace',
                'label' => 'Justification du besoin',
                'type' => 'textarea',
                'required' => true,
                'value' => $this->firstNonEmpty(
                    trim((string) ($details['descriptionBesoin'] ?? '')),
                    trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                    trim((string) ($generalContext['description'] ?? ''))
                ),
            ];

            if ('' !== $workplaceLocation) {
                $customFields[] = [
                    'key' => 'ai_zone_impactee_workplace',
                    'label' => 'Zone ou poste concerne',
                    'type' => 'text',
                    'required' => false,
                    'value' => $workplaceLocation,
                ];
            }

            $customFields[] = [
                'key' => 'ai_priorite_recommandee_workplace',
                'label' => 'Priorite recommandee',
                'type' => 'select',
                'required' => false,
                'value' => $workplacePriority,
                'options' => ['Normale', 'Haute', 'Critique'],
            ];

            if ($this->containsAnyWord($rawText, ['confidentiel', 'confidentielle', 'securise', 'securisee', 'secure'])) {
                $customFields[] = [
                    'key' => 'ai_niveau_confidentialite',
                    'label' => 'Niveau de confidentialite',
                    'type' => 'select',
                    'required' => false,
                    'value' => 'Eleve',
                    'options' => ['Standard', 'Eleve', 'Critique'],
                ];
            }
        }

        if (null !== $amount) {
            $customFields[] = [
                'key' => 'ai_montant_souhaite',
                'label' => 'Montant souhaite (TND)',
                'type' => 'number',
                'required' => true,
                'value' => (string) $amount,
            ];
        }

        if ('' !== $date) {
            $customFields[] = [
                'key' => 'ai_date_souhaitee',
                'label' => 'Date souhaitee',
                'type' => 'date',
                'required' => false,
                'value' => $date,
            ];
        }

        return $this->normalizeCustomFields($customFields, []);
    }

    /**
     * @return array{parking:int,formation:int,transport:int,finance:int,workplace:int}
     */
    private function computePromptIntentScores(string $text): array
    {
        $scores = [
            'parking' => 0,
            'formation' => 0,
            'transport' => 0,
            'finance' => 0,
            'workplace' => 0,
        ];

        $weightedKeywords = [
            'parking' => [
                'parking' => 3,
                'stationnement' => 3,
                'place de parking' => 3,
                'place reservee' => 3,
                'place reserve' => 3,
                'acces parking' => 2,
                'badge parking' => 2,
                'entree' => 1,
                'barriere' => 1,
            ],
            'formation' => [
                'formation' => 3,
                'certification' => 3,
                'cours' => 2,
                'training' => 2,
                'type de formation' => 2,
                'organisme de formation' => 2,
            ],
            'transport' => [
                'deplacement' => 3,
                'déplacement' => 3,
                'transport' => 2,
                'moyen de transport' => 3,
                'trajet' => 2,
                'navette' => 2,
                'vers' => 1,
            ],
            'finance' => [
                'salaire' => 3,
                'avance sur salaire' => 4,
                'remboursement' => 3,
                'facture' => 2,
                'montant' => 2,
            ],
            'workplace' => [
                'casier' => 3,
                'casiers' => 3,
                'espace calme' => 3,
                'appel confidentiel' => 3,
                'appels confidentiels' => 3,
                'confidentiel' => 2,
                'securise' => 2,
                'autorisation interne' => 3,
                'journee benevolat' => 3,
                'benevolat' => 2,
                'eclairage' => 3,
                'lumiere' => 2,
                'fatigue visuelle' => 3,
                'poste de travail' => 2,
                'bureau' => 2,
                'open space' => 2,
            ],
        ];

        foreach ($weightedKeywords as $intent => $keywords) {
            foreach ($keywords as $keyword => $weight) {
                if ($this->containsWord($text, (string) $keyword) || $this->containsFragment($text, (string) $keyword)) {
                    $scores[$intent] += (int) $weight;
                }
            }
        }

        return $scores;
    }

    /**
    * @param array{parking:int,formation:int,transport:int,finance:int,workplace:int} $scores
     */
    private function resolveDominantIntent(array $scores): string
    {
        $dominant = '';
        $maxScore = 0;

        foreach ($scores as $intent => $score) {
            if ($score > $maxScore) {
                $maxScore = $score;
                $dominant = (string) $intent;
            }
        }

        return $maxScore > 0 ? $dominant : '';
    }

    private function inferWorkplaceNeedType(string $text): string
    {
        if ($this->containsAnyWord($text, ['casier', 'casiers'])) {
            return 'Casier securise';
        }

        if ($this->containsAnyWord($text, ['eclairage', 'lumiere', 'fatigue visuelle'])) {
            return 'Ajustement eclairage';
        }

        if ($this->containsAnyWord($text, ['espace calme', 'appel confidentiel', 'appels confidentiels', 'confidentiel'])) {
            return 'Espace calme';
        }

        if ($this->containsAnyWord($text, ['autorisation interne', 'journee benevolat', 'benevolat', 'benevole'])) {
            return 'Autorisation interne';
        }

        if ($this->containsAnyWord($text, ['poste de travail', 'bureau', 'open space', 'amenagement'])) {
            return 'Amenagement poste';
        }

        return 'Autre';
    }

    private function extractWorkplaceLocationHint(string $text): string
    {
        if (preg_match('/\b(?:au|dans|sur)\s+(?:mon|ma|le|la|l[\'’]|un|une)?\s*([A-Za-zÀ-ÿ0-9\'’\- ]{2,60})(?=\s+(?:car|pour|avec|afin|et|je\b|j\b|nous\b|on\b)|[\.,;:"\'»”]|$)/iu', $text, $matches) === 1) {
            return $this->cleanupLocationCandidate((string) ($matches[1] ?? ''));
        }

        if ($this->containsWord($text, 'open space')) {
            return 'Open space';
        }

        if ($this->containsWord($text, 'bureau')) {
            return 'Bureau';
        }

        if ($this->containsWord($text, 'poste')) {
            return 'Poste de travail';
        }

        return '';
    }

    private function inferWorkplacePriority(string $text): string
    {
        if ($this->containsAnyWord($text, ['confidentiel', 'confidentielle', 'securise', 'securisee', 'fatigue visuelle'])) {
            return 'Haute';
        }

        if ($this->containsAnyWord($text, ['urgent', 'urgence', 'immediat', 'immédiat', 'bloquant'])) {
            return 'Critique';
        }

        return 'Normale';
    }

    /**
     * @param mixed $value
     */
    private function toBooleanFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return 1 === $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'oui'], true);
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $customFields
     */
    private function shouldReplaceBaseFields(bool $replaceBaseRequested, array $customFields): bool
    {
        if (!$replaceBaseRequested || count($customFields) < 2) {
            return false;
        }

        $requiredCount = 0;
        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }

            if (true === ($field['required'] ?? false)) {
                ++$requiredCount;
            }
        }

        return $requiredCount >= 1;
    }

    /**
     * @param array<int, array<string, mixed>> $customFields
     * @param array<int, string> $removeFields
     * @return array<string, string|int>
     */
    private function buildAutrePlanConfidence(
        array $customFields,
        array $removeFields,
        bool $replaceBase,
        string $correctedText,
        string $generatedDescription
    ): array {
        $score = 45;
        $customCount = count($customFields);
        $removeCount = count($removeFields);
        $requiredCount = 0;
        $filledCount = 0;

        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }

            if (true === ($field['required'] ?? false)) {
                ++$requiredCount;
            }

            if ('' !== trim((string) ($field['value'] ?? ''))) {
                ++$filledCount;
            }
        }

        $score += min(24, $customCount * 6);
        $score += min(12, $filledCount * 2);

        if ($requiredCount > 0) {
            $score += 6;
        }

        if ($replaceBase) {
            $score -= 12;
        }

        if (0 === $customCount && 0 === $removeCount) {
            $score -= 8;
        }

        if (mb_strlen($correctedText) >= 20) {
            $score += 6;
        }

        if (mb_strlen($generatedDescription) >= 35) {
            $score += 8;
        }

        $score = max(15, min(96, $score));

        if ($score >= 75) {
            return [
                'score' => $score,
                'label' => 'Elevee',
                'tone' => 'success',
                'message' => 'Le plan de champs est solide et bien exploitable.',
            ];
        }

        if ($score >= 55) {
            return [
                'score' => $score,
                'label' => 'Moyenne',
                'tone' => 'info',
                'message' => 'Le plan est utile mais merite une verification rapide.',
            ];
        }

        return [
            'score' => $score,
            'label' => 'Faible',
            'tone' => 'warning',
            'message' => 'Le plan est prudent, completez manuellement si besoin.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $primary
     * @param array<int, array<string, mixed>> $secondary
     * @return array<int, array<string, mixed>>
     */
    private function mergeCustomFields(array $primary, array $secondary): array
    {
        $merged = [];
        $used = [];

        foreach ([$primary, $secondary] as $group) {
            foreach ($group as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $key = trim((string) ($field['key'] ?? ''));
                if ('' === $key || isset($used[$key])) {
                    continue;
                }

                $used[$key] = true;
                $merged[] = $field;

                if (count($merged) >= 8) {
                    return $merged;
                }
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function inferDefaultTitle(array $generalContext): string
    {
        $text = strtolower($this->buildInferenceText($generalContext));

        if (str_contains($text, 'avance') && str_contains($text, 'salaire')) {
            return 'Avance sur salaire';
        }

        if (str_contains($text, 'remboursement')) {
            return 'Demande de remboursement';
        }

        if (str_contains($text, 'attestation')) {
            return 'Demande d attestation';
        }

        return $this->firstNonEmpty(
            trim((string) ($generalContext['titre'] ?? '')),
            'Demande personnalisee'
        );
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function inferUrgenceFromDescription(array $generalContext): string
    {
        $text = strtolower($this->buildInferenceText($generalContext));

        if (
            str_contains($text, 'urgent') ||
            str_contains($text, 'urgence') ||
            str_contains($text, 'immediat') ||
            str_contains($text, 'au plus vite')
        ) {
            return 'Urgente';
        }

        return 'Normale';
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function extractFrenchDate(array $generalContext): string
    {
        $text = strtolower($this->buildInferenceText($generalContext));

        if (preg_match('/\b(\d{1,2})\s+(janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)(?:\s+(\d{4}))?\b/u', $text, $matches) !== 1) {
            return '';
        }

        $day = (int) ($matches[1] ?? 0);
        $monthRaw = (string) ($matches[2] ?? '');
        $year = isset($matches[3]) && '' !== $matches[3]
            ? (int) $matches[3]
            : (int) (new \DateTimeImmutable())->format('Y');

        if ($day < 1 || $day > 31) {
            return '';
        }

        $months = [
            'janvier' => 1,
            'fevrier' => 2,
            'février' => 2,
            'mars' => 3,
            'avril' => 4,
            'mai' => 5,
            'juin' => 6,
            'juillet' => 7,
            'aout' => 8,
            'août' => 8,
            'septembre' => 9,
            'octobre' => 10,
            'novembre' => 11,
            'decembre' => 12,
            'décembre' => 12,
        ];

        $month = $months[$monthRaw] ?? 0;
        if ($month < 1 || !checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function extractAmountFromText(string $text): ?float
    {
        if (preg_match('/\b(\d+(?:[\.,]\d{1,2})?)\s*(dt|tnd|dinar|dinars)\b/i', $text, $matches) === 1) {
            $value = str_replace(',', '.', (string) ($matches[1] ?? ''));
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function inferFormationType(string $text): string
    {
        $knownTopic = $this->extractKnownFormationKeywordTopic($text);
        if ('' !== $knownTopic) {
            return str_contains($this->normalizeForSearch($knownTopic), 'certif')
                ? 'Certification'
                : 'Formation externe';
        }

        $explicitType = $this->extractExplicitFormationType($text);
        if ('' !== $explicitType) {
            return $explicitType;
        }

        if ($this->containsWord($text, 'certification')) {
            return 'Certification';
        }

        if ($this->containsWord($text, 'formation interne') || ($this->containsWord($text, 'formation') && $this->containsWord($text, 'interne'))) {
            return 'Formation interne';
        }

        if ($this->containsWord($text, 'formation externe') || ($this->containsWord($text, 'formation') && $this->containsWord($text, 'externe'))) {
            return 'Formation externe';
        }

        if (
            $this->containsWord($text, 'type de formation') ||
            preg_match('/formation\s+est\s+une\s+formation/iu', $text) === 1
        ) {
            return 'Autre';
        }

        return '';
    }

    private function inferDefaultFormationType(string $text, string $formationName): string
    {
        $normalizedName = $this->normalizeForSearch($formationName);
        if ('' !== $normalizedName) {
            if (
                str_contains($normalizedName, 'certif') ||
                str_contains($normalizedName, 'certification') ||
                str_contains($normalizedName, 'iso')
            ) {
                return 'Certification';
            }

            // For named topics like Java/UI/UX, default to external training.
            return 'Formation externe';
        }

        return $this->containsAnyWord($text, ['formation', 'cours', 'training'])
            ? 'Formation externe'
            : '';
    }

    private function extractExplicitFormationType(string $text): string
    {
        $normalized = $this->normalizeForSearch($text);
        if ('' === $normalized) {
            return '';
        }

        if (preg_match('/type de formation\s*(?:est|:|-)?\s*(interne|externe|certification|autre)\b/i', $normalized, $matches) === 1) {
            return match (strtolower((string) ($matches[1] ?? ''))) {
                'interne' => 'Formation interne',
                'externe' => 'Formation externe',
                'certification' => 'Certification',
                'autre' => 'Autre',
                default => '',
            };
        }

        return '';
    }

    private function extractCurrentLocation(string $rawText): string
    {
        $routeLocations = $this->extractRouteLocations($rawText);
        if ('' !== $routeLocations['from']) {
            return $routeLocations['from'];
        }

        $location = $this->extractLocationFromDeparturePhrase($rawText);
        if ('' !== $location) {
            return $location;
        }

        if (preg_match('/(?:lieu\s+de\s+d[ée]part(?:\s+actuel)?|depart\s+actuel|d[ée]part\s+de)\s*[:\-]?\s*([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)(?=\s+(?:vers|a|à|pour|la\s+formation|le\s+type|type\s+de\s+formation|formation\s+est)|[\.,;"\'»”]|$)/iu', $rawText, $matches) === 1) {
            return $this->sanitizeLikelyLocation((string) ($matches[1] ?? ''));
        }

        if (preg_match('/(?:actuellement|je\s+suis\s+actuellement|je\s+suis|situee?|située?)\s+(?:a|à|dans)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80})/iu', $rawText, $matches) === 1) {
            return $this->sanitizeLikelyLocation((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractTargetLocation(string $rawText): string
    {
        $routeLocations = $this->extractRouteLocations($rawText);
        if ('' !== $routeLocations['to']) {
            return $routeLocations['to'];
        }

        if (preg_match('/(?:lieu\s+de\s+d[ée]part(?:\s+actuel)?\s*[:\-]?\s*[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?\s+vers\s+|\bvers\s+)([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)(?=\s+(?:la\s+formation|le\s+type|type\s+de\s+formation|formation\s+est|je\b|j\b|nous\b|on\b)|[\.,;"\'»”]|$)/iu', $rawText, $matches) === 1) {
            return $this->sanitizeLikelyLocation((string) ($matches[1] ?? ''));
        }

        if (preg_match('/formation\s+(?:a|à|dans)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)(?=\s+(?:la\s+formation|le\s+type|type\s+de\s+formation|formation\s+est)|[\.,;"\'»”]|$)/iu', $rawText, $matches) === 1) {
            return $this->sanitizeLikelyLocation((string) ($matches[1] ?? ''));
        }

        if (preg_match('/(?:vers|destination\s*:?)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)(?=\s+(?:la\s+formation|le\s+type|type\s+de\s+formation|formation\s+est)|[\.,;"\'»”]|$)/iu', $rawText, $matches) === 1) {
            return $this->sanitizeLikelyLocation((string) ($matches[1] ?? ''));
        }

        if (preg_match('/\bdans\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)(?=\s+(?:la\s+formation|le\s+type|type\s+de\s+formation|formation\s+est)|[\.,;"\'»”]|$)/iu', $rawText, $matches) === 1) {
            return $this->sanitizeLikelyLocation((string) ($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * @return array{from:string,to:string}
     */
    private function extractRouteLocations(string $rawText): array
    {
        $compactText = $this->normalizeForSearch($rawText);

        if (preg_match('/\bvers\s+([a-z0-9\- ]{2,60}?)(?=\b(?:pour|afin|car|avec|la|le|je|j|nous|on|formation|certification|transport)\b|$)/iu', $compactText, $toMatch) === 1) {
            $to = $this->sanitizeLikelyLocation((string) ($toMatch[1] ?? ''));
            $from = $this->extractLocationFromDeparturePhrase($rawText);

            if ('' !== $from && '' !== $to) {
                return ['from' => $from, 'to' => $to];
            }
        }

        if (
            preg_match('/(.{0,220}?)\bvers\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)(?=\s+(?:pour|afin|car|avec|type\s+de\s+formation|formation\s+est|le\s+type|la\s+formation|je\b|j\b|nous\b|on\b)\b|[\.,;"\'»”]|$)/iu', $rawText, $contextMatch) === 1
        ) {
            $leftContext = trim((string) ($contextMatch[1] ?? ''));
            $to = $this->sanitizeLikelyLocation((string) ($contextMatch[2] ?? ''));

            if (
                preg_match('/(?:de|du|des|d[\'’]|depuis)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80})$/iu', $leftContext, $fromMatch) === 1
            ) {
                $from = $this->sanitizeLikelyLocation((string) ($fromMatch[1] ?? ''));
                if ('' !== $from && '' !== $to) {
                    return ['from' => $from, 'to' => $to];
                }
            }
        }

        $patterns = [
            '/\b(?:(?:de|du|des)\s+|d[\'’]\s*)([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)\s+(?:vers|a|à|jusqu(?:\'|e)?\s+a?)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)(?=\s+(?:pour|afin|car|avec|type\s+de\s+formation|formation\s+est|le\s+type|la\s+formation|je\b|j\b|nous\b|on\b)\b|[\.,;"\'»”]|$)/iu',
            '/\b(?:depuis|depart\s+de)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)\s+(?:vers|a|à|jusqu(?:\'|e)?\s+a?)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80}?)(?=\s+(?:pour|afin|car|avec|type\s+de\s+formation|formation\s+est|le\s+type|la\s+formation|je\b|j\b|nous\b|on\b)\b|[\.,;"\'»”]|$)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $rawText, $matches) === 1) {
                $from = $this->sanitizeLikelyLocation((string) ($matches[1] ?? ''));
                $to = $this->sanitizeLikelyLocation((string) ($matches[2] ?? ''));

                if ('' === $from || '' === $to) {
                    continue;
                }

                return ['from' => $from, 'to' => $to];
            }
        }

        return ['from' => '', 'to' => ''];
    }

    private function extractLocationFromDeparturePhrase(string $rawText): string
    {
        $compactText = $this->normalizeForSearch($rawText);
        if ('' === $compactText) {
            return '';
        }

        $matchCount = preg_match_all('/\b(?:de|du|des|d|depuis|partant de)\s+([a-z0-9\- ]{2,60}?)(?=\s+(?:vers|pour|afin|car|avec|la|le|je|j|nous|on|formation|certification|transport)\b|$)/iu', $compactText, $matches);
        if (false === $matchCount || 0 === $matchCount) {
            return '';
        }

        $candidates = $matches[1] ?? [];
        if (!is_array($candidates) || [] === $candidates) {
            return '';
        }

        for ($index = count($candidates) - 1; $index >= 0; --$index) {
            $candidate = $this->sanitizeLikelyLocation((string) ($candidates[$index] ?? ''));
            if ('' !== $candidate) {
                return $candidate;
            }
        }

        return '';
    }

    private function cleanupLocationCandidate(string $value): string
    {
        $clean = trim($value);
        $clean = preg_replace('/[\.,;:]+$/', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        $clean = preg_replace('/^(?:bonjour|salut|slt|je\s+veux|je\s+souhaite|je\s+demande|un|une|moyen\s+de\s+transport)\s+/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/^(?:transport|deplacement|déplacement|voyage|trajet)\s+/iu', '', $clean) ?? $clean;

        if (preg_match('/\bvers\s+(.+)$/iu', $clean, $routeMatch) === 1) {
            $clean = trim((string) ($routeMatch[1] ?? ''));
        }

        $clean = preg_replace('/^(?:de|du|des|d[\'’])\s+/iu', '', $clean) ?? $clean;

        $clean = preg_replace('/\s+(?:la|le|les)\s+formation\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+type\s+de\s+formation\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+formation\s+est\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+moyen\s+de\s+transport\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+(?:je|j|nous|on)\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+(?:depuis|depart\s+de)\s+.*$/iu', '', $clean) ?? $clean;

        $parts = preg_split('/\s+(?:et|mais|car|pour|puis|avec|qui|que)\s+/iu', $clean);
        if (is_array($parts) && isset($parts[0])) {
            $clean = trim((string) $parts[0]);
        }

        return substr($clean, 0, 80);
    }

    private function sanitizeLikelyLocation(string $value): string
    {
        $candidate = $this->cleanupLocationCandidate($value);
        return $this->isLikelyLocationCandidate($candidate) ? $candidate : '';
    }

    private function isLikelyLocationCandidate(string $value): bool
    {
        $candidate = trim($value);
        if ('' === $candidate) {
            return false;
        }

        $normalized = $this->normalizeForSearch($candidate);
        if ('' === $normalized) {
            return false;
        }

        if (preg_match('/\d/', $normalized) === 1) {
            return false;
        }

        if (preg_match('/\b(de|vers|a|à|depuis)\b/iu', $normalized) === 1 && preg_match('/\s/', $normalized) === 1) {
            return false;
        }

        $forbiddenTokens = [
            'maniere', 'claire', 'conforme', 'procedure', 'procedures', 'interne', 'internes',
            'demande', 'besoin', 'souhaite', 'soumettre', 'traiter', 'complement', 'information',
            'retour', 'merci', 'avance', 'bonjour', 'cette', 'correspond', 'utile', 'moyen', 'transport',
            'type', 'formation', 'ui', 'ux', 'deplacement', 'trajet', 'destination', 'souhaitee', 'souhaitee',
        ];

        foreach ($forbiddenTokens as $token) {
            if ($this->containsWord($normalized, $token)) {
                return false;
            }
        }

        return true;
    }

    private function extractFormationTopic(string $text): string
    {
        $patterns = [
            '/(?:formation|certification)\s+(?:en|sur|de|d[\'’])\s*([A-Za-z0-9+\/().\-\s]{2,80})/iu',
            '/\bformation\s+([A-Za-z0-9+\/().\-\s]{2,80})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) !== 1) {
                continue;
            }

            $candidate = trim((string) ($matches[1] ?? ''));
            $candidate = preg_replace('/\s+/', ' ', $candidate) ?? $candidate;
            $candidate = preg_replace('/[\.,;:]+$/', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\b(?:dans|a|à|avec|pour|car|afin|de|du|des|d[\'’])\b.*$/iu', '', $candidate) ?? $candidate;
            $candidate = trim($candidate);

            if (strlen($candidate) < 2) {
                continue;
            }

            $normalized = $this->normalizeForSearch($candidate);
            if (in_array($normalized, ['interne', 'externe', 'certification', 'autre'], true)) {
                continue;
            }

            return substr($candidate, 0, 80);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function buildInferenceText(array $generalContext): string
    {
        $prompt = (string) ($generalContext['aiDescriptionPrompt'] ?? '');
        $primaryIntent = $this->extractPrimaryIntentFromPrompt($prompt);

        // For Autre AI generation, prioritize the fresh prompt to avoid leaking stale
        // context from previous title/description runs when user regenerates.
        if ('' !== trim($prompt)) {
            return trim(implode(' ', [
                $primaryIntent,
                $prompt,
            ]));
        }

        return trim(implode(' ', [
            $primaryIntent,
            (string) ($generalContext['titre'] ?? ''),
            (string) ($generalContext['description'] ?? ''),
            $prompt,
        ]));
    }

    private function extractPrimaryIntentFromPrompt(string $prompt): string
    {
        $text = trim($prompt);
        if ('' === $text) {
            return '';
        }

        if (preg_match("/[\"'«](.{12,260}?)[\"'»]/u", $text, $matches) === 1) {
            $quoted = trim((string) ($matches[1] ?? ''));
            if ('' !== $quoted) {
                return $quoted;
            }
        }

        $clean = $text;
        $clean = preg_replace('/\b(?:bonjour|salut|slt)\b[\s,;:]*/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\bje\s+souhaite\s+(?:soumettre|faire|creer|cr[eé]er)\s+une\s+demande\s+(?:li[ée]e\s+[aà]|concernant|pour)\s*/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\bcette\s+demande\s+correspond\s+.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        return trim((string) substr($clean, 0, 280));
    }

    private function extractParkingZone(string $text): string
    {
        if (preg_match("/\b(?:proche|pres|pr[eè]s|a\s+cote\s+de|a\s+proximite\s+de)\s+([A-Za-zÀ-ÿ0-9'’\- ]{2,80})/iu", $text, $matches) === 1) {
            return $this->cleanupLocationCandidate((string) ($matches[1] ?? ''));
        }

        if ($this->containsWord($text, 'entree')) {
            return 'Entree principale';
        }

        if ($this->containsWord($text, 'parking')) {
            return 'Parking principal';
        }

        return '';
    }

    private function extractPreferredArrivalTime(string $text): string
    {
        if (preg_match('/\b([01]?\d|2[0-3])[:h]([0-5]\d)\b/u', $text, $matches) === 1) {
            return sprintf('%02d:%02d', (int) ($matches[1] ?? 0), (int) ($matches[2] ?? 0));
        }

        if ($this->containsAnyWord($text, ['jarrive tot', 'j arrive tot', 'arrive tot', 'arrivee tot', 'tres tot'])) {
            return 'Tot le matin';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function inferCategoryFromDescription(array $generalContext): string
    {
        $text = $this->buildInferenceText($generalContext);
        $scores = $this->scoreCategoryCandidates($text, []);

        $bestCategory = '';
        $bestScore = 0;
        foreach ($scores as $category => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCategory = (string) $category;
            }
        }

        return $bestScore > 0 ? $bestCategory : '';
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     * @return array<string, int>
     */
    private function scoreCategoryCandidates(string $text, array $categoryTypes): array
    {
        $weights = [
            'Ressources Humaines' => [
                'conge' => 3,
                'attestation' => 3,
                'certificat' => 3,
                'mutation' => 3,
                'demission' => 3,
                'rh' => 2,
                'ressources humaines' => 3,
            ],
            'Administrative' => [
                'avance sur salaire' => 4,
                'salaire' => 3,
                'remboursement' => 3,
                'facture' => 2,
                'montant' => 2,
                'badge' => 2,
                'carte de visite' => 2,
                'materiel de bureau' => 2,
            ],
            'Informatique' => [
                'informatique' => 3,
                'acces systeme' => 4,
                'acces application' => 3,
                'compte' => 2,
                'logiciel' => 3,
                'bug' => 3,
                'panne' => 3,
                'ordinateur' => 2,
                'reseau' => 2,
                'wifi' => 2,
                'email' => 2,
                'imprimante' => 2,
            ],
            'Formation' => [
                'formation' => 3,
                'certification' => 3,
                'cours' => 2,
                'training' => 2,
                'examen' => 2,
                'ui ux' => 2,
                'java' => 2,
            ],
            'Organisation du travail' => [
                'teletravail' => 4,
                'travail a distance' => 3,
                'changement horaires' => 3,
                'horaire' => 2,
                'heures supplementaires' => 4,
                'heure sup' => 3,
                'overtime' => 3,
            ],
        ];

        $scores = [];
        foreach ($weights as $category => $keywords) {
            if ([] !== $categoryTypes && !isset($categoryTypes[$category])) {
                continue;
            }

            $scores[$category] = 0;
            foreach ($keywords as $keyword => $weight) {
                if ($this->containsWord($text, $keyword) || $this->containsFragment($text, $keyword)) {
                    $scores[$category] += (int) $weight;
                }
            }
        }

        return $scores;
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($this->containsFragment($text, (string) $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function containsFragment(string $text, string $fragment): bool
    {
        $normalizedText = $this->normalizeForSearch($text);
        $normalizedFragment = $this->normalizeForSearch($fragment);

        if ('' === $normalizedText || '' === $normalizedFragment) {
            return false;
        }

        return str_contains($normalizedText, $normalizedFragment);
    }

    private function containsWord(string $text, string $keyword): bool
    {
        $needle = trim($this->normalizeForSearch($keyword));
        if ('' === $needle) {
            return false;
        }

        $haystack = $this->normalizeForSearch($text);
        if ('' === $haystack) {
            return false;
        }

        $escaped = preg_quote($needle, '/');

        return 1 === preg_match('/(?<![a-z0-9])' . $escaped . '(?![a-z0-9])/', $haystack);
    }

    private function containsAnyWord(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($this->containsWord($text, (string) $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeForSearch(string $text): string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        if ('' === $normalized) {
            return '';
        }

        if (class_exists('Transliterator')) {
            $transliterated = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')?->transliterate($normalized);
            if (is_string($transliterated) && '' !== $transliterated) {
                $normalized = $transliterated;
            }
        } else {
            $iconv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (is_string($iconv) && '' !== $iconv) {
                $normalized = strtolower($iconv);
            }
        }

        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param array<int, mixed> $customFields
     * @param array<int, string> $forbiddenKeys
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCustomFields(array $customFields, array $forbiddenKeys): array
    {
        $normalized = [];
        $seenKeys = [];
        $allowedTypes = ['text', 'textarea', 'select', 'number', 'date'];

        foreach ($customFields as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $rawKey = strtolower(trim((string) ($candidate['key'] ?? '')));
            $slug = preg_replace('/[^a-z0-9_]+/', '_', $rawKey) ?? '';
            $slug = trim($slug, '_');
            if ('' === $slug) {
                continue;
            }

            if (!str_starts_with($slug, 'ai_')) {
                $slug = 'ai_' . $slug;
            }

            if (in_array($slug, $forbiddenKeys, true) || isset($seenKeys[$slug])) {
                continue;
            }

            $type = strtolower(trim((string) ($candidate['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }

            $label = trim((string) ($candidate['label'] ?? 'Champ complementaire'));
            if ('' === $label) {
                $label = 'Champ complementaire';
            }

            $value = trim((string) ($candidate['value'] ?? ''));

            if ('date' === $type && '' !== $value && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $value = '';
            }

            if ('number' === $type && '' !== $value && !is_numeric($value)) {
                $value = '';
            }

            $field = [
                'key' => $slug,
                'label' => substr($label, 0, 80),
                'type' => $type,
                'required' => (bool) ($candidate['required'] ?? false),
                'value' => substr($value, 0, 500),
            ];

            if ('select' === $type) {
                $options = [];
                $rawOptions = $candidate['options'] ?? [];
                if (is_array($rawOptions)) {
                    foreach ($rawOptions as $optionRaw) {
                        $option = trim((string) $optionRaw);
                        if ('' === $option) {
                            continue;
                        }
                        $options[$option] = substr($option, 0, 60);
                        if (count($options) >= 8) {
                            break;
                        }
                    }
                }

                $field['options'] = array_values($options);

                if ([] === $field['options']) {
                    $field['type'] = 'text';
                }
            }

            $normalized[] = $field;
            $seenKeys[$slug] = true;

            if (count($normalized) >= 8) {
                break;
            }
        }

        return $normalized;
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ('' !== trim($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     * @param array<int, string> $options
     */
    private function normalizeValue(string $key, mixed $value, array $options): string
    {
        $text = trim((string) $value);

        if ('' === $text) {
            return '';
        }

        if ('dateSouhaiteeAutre' === $key) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : '';
        }

        if ('niveauUrgenceAutre' === $key) {
            foreach ($options as $option) {
                if (strtolower($text) === strtolower($option)) {
                    return $option;
                }
            }

            return $options[1] ?? $options[0] ?? 'Normale';
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, array<int, string>> $categoryTypes
     * @param array<int, string> $priorities
     * @return array<string, mixed>
     */
    private function normalizeClassificationSuggestion(array $parsed, string $rawText, array $categoryTypes, array $priorities): array
    {
        $correctedText = trim((string) ($parsed['correctedText'] ?? ''));
        if ('' === $correctedText) {
            $correctedText = $this->autoCorrectSuggestionText($rawText);
        } else {
            $correctedText = $this->autoCorrectSuggestionText($correctedText);
        }

        $categorie = trim((string) ($parsed['categorie'] ?? ''));
        $typeDemande = trim((string) ($parsed['typeDemande'] ?? ''));
        $priorite = strtoupper(trim((string) ($parsed['priorite'] ?? 'NORMALE')));
        $titre = trim((string) ($parsed['titre'] ?? ''));
        $description = trim((string) ($parsed['description'] ?? ''));
        $confidence = (float) ($parsed['confidence'] ?? 0.0);

        $categorie = $this->resolveKnownCategoryAlias($categorie, $categoryTypes);
        $typeDemande = $this->resolveKnownTypeAlias($typeDemande, $categoryTypes, $categorie);

        $allTypes = [];
        foreach ($categoryTypes as $category => $types) {
            foreach ($types as $type) {
                $allTypes[(string) $type] = (string) $category;
            }
        }

        $explicitTypeMatch = $this->findExplicitTypeMatch($correctedText, $categoryTypes);
        if (null !== $explicitTypeMatch) {
            $categorie = $explicitTypeMatch['categorie'];
            $typeDemande = $explicitTypeMatch['typeDemande'];
        }

        if ('' === $categorie || !isset($categoryTypes[$categorie])) {
            $categorie = $this->inferCategoryFromDescription([
                'description' => $correctedText,
                'titre' => $titre,
            ]);
            $categorie = $this->resolveKnownCategoryAlias($categorie, $categoryTypes);
        }

        if ('' === $categorie || !isset($categoryTypes[$categorie])) {
            if ('' !== $typeDemande && isset($allTypes[$typeDemande])) {
                $categorie = $allTypes[$typeDemande];
            } else {
                $categorie = 'Autre';
            }
        }

        $typeDemande = $this->resolveKnownTypeAlias($typeDemande, $categoryTypes, $categorie);

        if ('' === $typeDemande || !in_array($typeDemande, $categoryTypes[$categorie] ?? [], true)) {
            $typeDemande = $this->inferTypeFromDescription($correctedText, $categorie, $categoryTypes);
            $typeDemande = $this->resolveKnownTypeAlias($typeDemande, $categoryTypes, $categorie);
        }

        if ('' === $typeDemande || !in_array($typeDemande, $categoryTypes[$categorie] ?? [], true)) {
            $typeDemande = $categoryTypes[$categorie][0] ?? 'Autre';
        }

        if (!in_array($priorite, $priorities, true)) {
            $priorite = $this->inferPriorityFromDescription($correctedText, $priorities);
        }

        if (!in_array($priorite, $priorities, true)) {
            $priorite = 'NORMALE';
        }

        if ('' === $titre) {
            $titre = $this->buildTitleFromType($typeDemande, $correctedText);
        }

        if ('' === $description) {
            $description = $correctedText;
        }

        if ($confidence <= 0 || $confidence > 1) {
            $confidence = $this->estimateClassificationConfidence($correctedText, $categorie, $typeDemande, $categoryTypes);
        } elseif ($confidence < 0.2) {
            $confidence = max(
                $confidence,
                $this->estimateClassificationConfidence($correctedText, $categorie, $typeDemande, $categoryTypes) * 0.85
            );
        }

        return [
            'correctedText' => $correctedText,
            'categorie' => $categorie,
            'typeDemande' => $typeDemande,
            'priorite' => $priorite,
            'titre' => $titre,
            'description' => $description,
            'confidence' => round($confidence, 2),
        ];
    }

    private function autoCorrectSuggestionText(string $text): string
    {
        $clean = trim((string) (preg_replace('/\s+/u', ' ', str_replace([',', ';'], ' ', $text)) ?? $text));
        if ('' === $clean) {
            return '';
        }

        $normalized = mb_strtolower($clean, 'UTF-8');

        $directReplacements = [
            '/\b(?:bjr|slt|salu+t?)\b/u' => 'bonjour',
            '/\b(?:jvux|jveux|j veu|j veu[x]?|jvx|j vu[x]?)\b/u' => 'je veux',
            '/\b(?:dqnde|deqnde|demde|dmande|demnde)\b/u' => 'demande',
            '/\b(?:conhe|conh[eé]|conje|congee|congéé|congee)\b/u' => 'conge',
            '/\b(?:qnnuel|qnuel|anuel)\b/u' => 'annuel',
            '/\b(?:tele travail|télé travail)\b/u' => 'teletravail',
            '/\b(?:remboursemnt|remboursemnt)\b/u' => 'remboursement',
            '/\b(?:acces syteme|acces system|acess systeme)\b/u' => 'acces systeme',
        ];

        foreach ($directReplacements as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
        }

        $knownTerms = [
            'bonjour', 'demande', 'conge', 'annuel', 'maladie', 'sans', 'solde', 'attestation', 'certificat', 'mutation', 'demission',
            'avance', 'salaire', 'remboursement', 'materiel', 'bureau', 'badge', 'acces', 'carte', 'visite',
            'informatique', 'logiciel', 'probleme', 'technique', 'formation', 'interne', 'externe', 'certification',
            'teletravail', 'horaire', 'heures', 'supplementaires', 'parking', 'stationnement', 'transport', 'deplacement',
        ];

        $knownMap = [];
        foreach ($knownTerms as $term) {
            $knownMap[$this->normalizeForSearch($term)] = $term;
        }

        $parts = preg_split('/(\s+)/u', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            $parts = [$normalized];
        }

        foreach ($parts as $idx => $part) {
            if ($part === '' || preg_match('/^\s+$/u', $part) === 1) {
                continue;
            }

            $token = trim($part);
            $normalizedToken = $this->normalizeForSearch($token);
            if (strlen($normalizedToken) < 4 || isset($knownMap[$normalizedToken])) {
                continue;
            }

            $best = '';
            $bestDistance = PHP_INT_MAX;

            foreach ($knownMap as $normalizedKnown => $knownOriginal) {
                if (abs(strlen($normalizedKnown) - strlen($normalizedToken)) > 2) {
                    continue;
                }

                $distance = levenshtein($normalizedToken, $normalizedKnown);
                $threshold = max(1, (int) floor(strlen($normalizedKnown) / 4));
                if ($distance <= $threshold && $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $knownOriginal;
                }
            }

            if ('' !== $best) {
                $parts[$idx] = $best;
            }
        }

        $corrected = trim((string) (preg_replace('/\s+/u', ' ', implode('', $parts)) ?? implode('', $parts)));

        // Keep phrase-level readability after token correction.
        $corrected = preg_replace('/\bje\s+veux\s+un\s+de\b/u', 'je veux une', $corrected) ?? $corrected;

        return $corrected;
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     */
    private function inferTypeFromDescription(string $text, string $categorie, array $categoryTypes, bool $allowFallback = true): string
    {
        $availableTypes = $categoryTypes[$categorie] ?? [];
        $scores = $this->scoreTypeCandidates($text, $categorie, $categoryTypes);

        $bestType = '';
        $bestScore = 0;
        foreach ($scores as $type => $score) {
            if ('Autre' === $type) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestType = (string) $type;
            }
        }

        if ('' !== $bestType && $bestScore > 0 && in_array($bestType, $availableTypes, true)) {
            return $bestType;
        }

        if (!$allowFallback) {
            return '';
        }

        if (in_array('Autre', $availableTypes, true)) {
            return 'Autre';
        }

        return $availableTypes[0] ?? '';
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     * @return array<string, int>
     */
    private function scoreTypeCandidates(string $text, string $categorie, array $categoryTypes): array
    {
        $availableTypes = $categoryTypes[$categorie] ?? [];
        $keywordMap = $this->getTypeKeywordMap();
        $scores = [];

        foreach ($availableTypes as $type) {
            $typeLabel = (string) $type;
            $scores[$typeLabel] = 0;

            $normalizedType = $this->normalizeForSearch($typeLabel);
            if ('' !== $normalizedType && $this->containsFragment($text, $normalizedType)) {
                $scores[$typeLabel] += 6;
            }

            $tokens = preg_split('/\s+/', $normalizedType) ?: [];
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if (strlen($token) < 3 || in_array($token, ['de', 'du', 'des', 'sur'], true)) {
                    continue;
                }

                if ($this->containsWord($text, $token)) {
                    $scores[$typeLabel] += 1;
                }
            }

            $keywords = $keywordMap[$typeLabel] ?? [];
            foreach ($keywords as $keyword => $weight) {
                if ($this->containsWord($text, (string) $keyword) || $this->containsFragment($text, (string) $keyword)) {
                    $scores[$typeLabel] += (int) $weight;
                }
            }
        }

        return $scores;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function getTypeKeywordMap(): array
    {
        return [
            'Conge' => ['conge' => 3, 'vacance' => 2, 'repos' => 2, 'absence' => 2, 'maladie' => 2],
            'Attestation de travail' => ['attestation de travail' => 4, 'attestation travail' => 3],
            'Attestation de salaire' => ['attestation de salaire' => 4, 'fiche de salaire' => 3, 'salaire' => 2],
            'Certificat de travail' => ['certificat de travail' => 4],
            'Mutation' => ['mutation' => 4, 'changement de departement' => 3, 'transfert' => 3],
            'Demission' => ['demission' => 4, 'demissionner' => 3, 'depart de l entreprise' => 3],
            'Avance sur salaire' => ['avance sur salaire' => 5, 'avance salaire' => 4, 'salaire avance' => 3],
            'Remboursement' => ['remboursement' => 4, 'rembourser' => 3, 'frais' => 2, 'facture' => 2],
            'Materiel de bureau' => ['materiel de bureau' => 4, 'fourniture' => 3, 'stylo' => 2, 'papier' => 2, 'mobilier' => 2],
            'Badge acces' => ['badge' => 4, 'badge perdu' => 4, 'acces badge' => 3],
            'Carte de visite' => ['carte de visite' => 4, 'business card' => 3],
            'Materiel informatique' => ['materiel informatique' => 4, 'ordinateur' => 3, 'pc' => 2, 'laptop' => 2, 'ecran' => 2, 'clavier' => 2, 'souris' => 2],
            'Acces systeme' => ['acces systeme' => 5, 'acces application' => 4, 'permission' => 3, 'compte' => 2, 'erp' => 2, 'crm' => 2, 'salesforce' => 3],
            'Logiciel' => ['logiciel' => 4, 'licence' => 3, 'software' => 3, 'installer' => 2],
            'Probleme technique' => ['probleme technique' => 5, 'bug' => 4, 'panne' => 4, 'imprimante' => 3, 'wifi' => 3, 'reseau' => 3, 'email' => 2],
            'Formation interne' => ['formation interne' => 5, 'interne' => 2],
            'Formation externe' => ['formation externe' => 5, 'formation' => 2, 'cours' => 2, 'training' => 2],
            'Certification' => ['certification' => 5, 'certif' => 4, 'examen' => 3],
            'Teletravail' => ['teletravail' => 5, 'travail a distance' => 4, 'remote' => 3],
            'Changement horaires' => ['changement horaires' => 5, 'horaire' => 3, 'emploi du temps' => 2],
            'Heures supplementaires' => ['heures supplementaires' => 5, 'heure sup' => 4, 'overtime' => 3],
            'Autre' => ['autre' => 1],
        ];
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     * @return array{categorie:string,typeDemande:string}|null
     */
    private function findExplicitTypeMatch(string $text, array $categoryTypes): ?array
    {
        $bestCategory = '';
        $bestType = '';
        $bestScore = 0;

        foreach ($categoryTypes as $category => $types) {
            $scores = $this->scoreTypeCandidates($text, (string) $category, $categoryTypes);
            foreach ($scores as $type => $score) {
                if ('Autre' === $type || !in_array($type, $types, true)) {
                    continue;
                }

                if ($score > $bestScore) {
                    $bestScore = (int) $score;
                    $bestCategory = (string) $category;
                    $bestType = (string) $type;
                }
            }
        }

        if ($bestScore >= 3 && '' !== $bestCategory && '' !== $bestType) {
            return [
                'categorie' => $bestCategory,
                'typeDemande' => $bestType,
            ];
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     */
    private function estimateClassificationConfidence(string $text, string $categorie, string $typeDemande, array $categoryTypes): float
    {
        $categoryScores = $this->scoreCategoryCandidates($text, $categoryTypes);
        $categoryScore = (int) ($categoryScores[$categorie] ?? 0);

        $typeScores = $this->scoreTypeCandidates($text, $categorie, $categoryTypes);
        $typeScore = (int) ($typeScores[$typeDemande] ?? 0);

        $categoryStrength = min(1.0, $categoryScore / 8);
        $typeStrength = min(1.0, $typeScore / 10);
        $confidence = 0.30 + (0.30 * $categoryStrength) + (0.40 * $typeStrength);

        if ('Autre' === $typeDemande) {
            $confidence = min($confidence, 0.62);
        }

        return max(0.18, min(0.95, $confidence));
    }

    /**
     * @param array<int, string> $priorities
     */
    private function inferPriorityFromDescription(string $text, array $priorities): string
    {
        $normalized = strtolower($text);

        if (
            str_contains($normalized, 'urgent') ||
            str_contains($normalized, 'urgence') ||
            str_contains($normalized, 'bloquant') ||
            str_contains($normalized, 'au plus vite') ||
            str_contains($normalized, 'immediat')
        ) {
            return in_array('HAUTE', $priorities, true) ? 'HAUTE' : ($priorities[0] ?? 'NORMALE');
        }

        if (
            str_contains($normalized, 'quand possible') ||
            str_contains($normalized, 'pas urgent') ||
            str_contains($normalized, 'faible')
        ) {
            return in_array('BASSE', $priorities, true) ? 'BASSE' : ($priorities[0] ?? 'NORMALE');
        }

        return in_array('NORMALE', $priorities, true) ? 'NORMALE' : ($priorities[0] ?? 'NORMALE');
    }

    private function buildTitleFromType(string $typeDemande, string $correctedText): string
    {
        if ('' !== trim($typeDemande)) {
            return 'Demande - ' . $typeDemande;
        }

        $excerpt = trim($correctedText);
        if (strlen($excerpt) > 80) {
            $excerpt = substr($excerpt, 0, 77) . '...';
        }

        return '' !== $excerpt ? $excerpt : 'Nouvelle demande';
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     */
    private function resolveKnownCategoryAlias(string $candidate, array $categoryTypes): string
    {
        $candidate = trim($candidate);
        if ('' === $candidate) {
            return '';
        }

        if (isset($categoryTypes[$candidate])) {
            return $candidate;
        }

        $normalizedCandidate = $this->normalizeForSearch($candidate);
        foreach (array_keys($categoryTypes) as $knownCategory) {
            if ($this->normalizeForSearch((string) $knownCategory) === $normalizedCandidate) {
                return (string) $knownCategory;
            }
        }

        return $candidate;
    }

    /**
     * @param array<string, array<int, string>> $categoryTypes
     */
    private function resolveKnownTypeAlias(string $candidate, array $categoryTypes, string $category = ''): string
    {
        $candidate = trim($candidate);
        if ('' === $candidate) {
            return '';
        }

        $normalizedCandidate = $this->normalizeForSearch($candidate);
        if ('' === $normalizedCandidate) {
            return $candidate;
        }

        $candidateSets = [];
        if ('' !== $category && isset($categoryTypes[$category])) {
            $candidateSets[] = $categoryTypes[$category];
        }
        foreach ($categoryTypes as $types) {
            $candidateSets[] = $types;
        }

        foreach ($candidateSets as $types) {
            if (!is_array($types)) {
                continue;
            }

            foreach ($types as $knownType) {
                $knownType = (string) $knownType;
                if ($knownType === $candidate) {
                    return $knownType;
                }

                if ($this->normalizeForSearch($knownType) === $normalizedCandidate) {
                    return $knownType;
                }
            }
        }

        return $candidate;
    }

    /**
     * @return array<int, string>
     */
    private function extractAllFrenchDates(string $text): array
    {
        $normalized = strtolower($text);
        preg_match_all('/\b(\d{1,2})\s+(janvier|fevrier|fÃ©vrier|mars|avril|mai|juin|juillet|aout|aoÃ»t|septembre|octobre|novembre|decembre|dÃ©cembre)(?:\s+(\d{4}))?\b/u', $normalized, $matches, PREG_SET_ORDER);

        $dates = [];
        foreach ($matches as $match) {
            $day = (int) ($match[1] ?? 0);
            $monthRaw = (string) ($match[2] ?? '');
            $year = isset($match[3]) && '' !== $match[3]
                ? (int) $match[3]
                : (int) (new \DateTimeImmutable())->format('Y');

            $months = [
                'janvier' => 1,
                'fevrier' => 2,
                'fÃ©vrier' => 2,
                'mars' => 3,
                'avril' => 4,
                'mai' => 5,
                'juin' => 6,
                'juillet' => 7,
                'aout' => 8,
                'aoÃ»t' => 8,
                'septembre' => 9,
                'octobre' => 10,
                'novembre' => 11,
                'decembre' => 12,
                'dÃ©cembre' => 12,
            ];

            $month = $months[$monthRaw] ?? 0;
            if ($month > 0 && checkdate($month, $day, $year)) {
                $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return array_values(array_unique($dates));
    }

    private function extractDaysCount(string $text, array $dates): string
    {
        if (preg_match('/\b(\d+)\s+jours?\b/i', $text, $matches) === 1) {
            return (string) ((int) ($matches[1] ?? 0));
        }

        if (count($dates) >= 2) {
            try {
                $start = new \DateTimeImmutable($dates[0]);
                $end = new \DateTimeImmutable($dates[1]);
                if ($end >= $start) {
                    return (string) ($start->diff($end)->days + 1);
                }
            } catch (\Throwable) {
            }
        }

        return '';
    }

    private function extractIntegerNearKeywords(string $text, array $keywords): string
    {
        foreach ($keywords as $keyword) {
            if (preg_match('/\b(\d+)\s+' . preg_quote($keyword, '/') . 's?\b/i', $text, $matches) === 1) {
                return (string) ((int) ($matches[1] ?? 0));
            }
        }

        return '';
    }

    private function extractFirstInteger(string $text): string
    {
        if (preg_match('/\b(\d+)\b/', $text, $matches) === 1) {
            return (string) ((int) ($matches[1] ?? 0));
        }

        return '';
    }

    private function extractRecipient(string $text): string
    {
        if (preg_match('/(?:destinataire|pour)\s*[:\-]?\s*([A-Za-z0-9À-ÿ\'\-\s]{3,80})/u', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractDepartment(string $text, bool $current): string
    {
        $patterns = $current
            ? ['/(?:departement actuel|service actuel|actuellement en)\s*[:\-]?\s*([A-Za-zÀ-ÿ\'\-\s]{3,80})/iu']
            : ['/(?:departement souhaite|service souhaite|vers)\s*[:\-]?\s*([A-Za-zÀ-ÿ\'\-\s]{3,80})/iu'];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }
        }

        return '';
    }

    private function extractTargetRole(string $text): string
    {
        if (preg_match('/(?:poste|fonction|role)\s*(?:souhaite|demand[ée])?\s*[:\-]?\s*([A-Za-zÀ-ÿ0-9\'\-\s]{3,80})/iu', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractMonthDuration(string $text): int
    {
        if (preg_match('/\b(\d+)\s*mois\b/i', $text, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }

    /**
     * @param array<int, string> $options
     * @param array<string, array<int, string>> $keywordMap
     */
    private function matchOptionByKeywords(array $options, string $text, array $keywordMap, string $fallback = ''): string
    {
        foreach ($keywordMap as $targetOption => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, strtolower((string) $keyword))) {
                    foreach ($options as $option) {
                        if (strcasecmp($option, $targetOption) === 0) {
                            return $option;
                        }
                    }
                }
            }
        }

        return $fallback;
    }

    /**
     * @param array<int, string> $options
     */
    private function matchMonthOption(array $options, int $months, string $text, string $specialOption = '', array $specialKeywords = []): string
    {
        foreach ($specialKeywords as $keyword) {
            if (str_contains($text, strtolower($keyword)) && '' !== $specialOption) {
                foreach ($options as $option) {
                    if (strcasecmp($option, $specialOption) === 0) {
                        return $option;
                    }
                }
            }
        }

        if ($months > 0) {
            foreach ($options as $option) {
                if (preg_match('/\b' . $months . '\s*mois\b/i', $option) === 1) {
                    return $option;
                }
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $options
     */
    private function matchClosestNumericOption(array $options, string $number): string
    {
        $target = (int) $number;
        if ($target <= 0) {
            return '';
        }

        $closest = '';
        $distance = PHP_INT_MAX;
        foreach ($options as $option) {
            $value = (int) preg_replace('/\D+/', '', $option);
            if ($value <= 0) {
                continue;
            }

            $delta = abs($value - $target);
            if ($delta < $distance) {
                $distance = $delta;
                $closest = $option;
            }
        }

        return $closest;
    }

    private function extractPhoneNumber(string $text): string
    {
        if (preg_match('/(\+?\d[\d\s]{6,}\d)/', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractEmailAddress(string $text): string
    {
        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractSystemName(string $text): string
    {
        if (preg_match('/(?:acces|acc[èe]s|sur|application|logiciel|outil)\s+(?:a|à|au|aux|de)?\s*([A-Za-z0-9._\- ]{2,60})/iu', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractVersion(string $text): string
    {
        if (preg_match('/\b(v(?:ersion)?\s*\d+(?:\.\d+)*)\b/i', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractTrainingName(string $text): string
    {
        $knownTopic = $this->extractKnownFormationKeywordTopic($text);
        if ('' !== $knownTopic) {
            return $knownTopic;
        }

        $patterns = [
            "/(?:nom(?:\\s+de)?\\s+la\\s+formation|intitule\\s+de\\s+formation|formation\\s+intitulee|formation\\s+intitulée|nom\\s+formation)\\s*[:\\-]?\\s*([A-Za-z0-9À-ÿ\\/+().'’\\-\\s]{2,120}?)(?=\\s+(?:pour|de|du|des|d['’]|vers|dans|sur|en|avec|car|afin|transport|deplacement|déplacement|bonjour|salut|slt|je\\b|j\\b|nous\\b|on\\b|type\\b|lieu\\b|date\\b)|[\\.,;:\"'»”]|$)/iu",
            "/(?:formation|certification)\\s*(?:en|sur|de|d['’])?\\s*([A-Za-z0-9À-ÿ\\/+().'’\\-\\s]{2,120}?)(?=\\s+(?:pour|de|du|des|d['’]|vers|dans|sur|en|avec|car|afin|transport|deplacement|déplacement|bonjour|salut|slt|je\\b|j\\b|nous\\b|on\\b|type\\b|lieu\\b|date\\b)|[\\.,;:\"'»”]|$)/iu",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) !== 1) {
                continue;
            }

            $candidate = $this->cleanupTrainingNameCandidate((string) ($matches[1] ?? ''));
            if ('' !== $candidate) {
                return $candidate;
            }
        }

        return '';
    }

    private function extractKnownFormationKeywordTopic(string $text): string
    {
        $normalized = $this->normalizeForSearch($text);
        if ('' === $normalized) {
            return '';
        }

        $topicMap = $this->loadFormationKeywordTopicMap();
        if ([] === $topicMap) {
            return '';
        }

        $bestLabel = '';
        $bestScore = 0;

        foreach ($topicMap as $label => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                $candidate = trim((string) $keyword);
                if ('' === $candidate) {
                    continue;
                }

                $normalizedKeyword = $this->normalizeForSearch($candidate);
                if ('' === $normalizedKeyword) {
                    continue;
                }

                // Only allow exact word matching for short terms to avoid noisy false positives.
                if ($this->containsWord($normalized, $normalizedKeyword)) {
                    $score += strlen($normalizedKeyword) >= 8 ? 2 : 1;
                    continue;
                }

                // Fragment matching is restricted to longer keywords/phrases.
                if (strlen($normalizedKeyword) >= 6 && str_contains($normalized, $normalizedKeyword)) {
                    $score += 1;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLabel = (string) $label;
            }
        }

        return $bestScore > 0 ? $bestLabel : '';
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function loadFormationKeywordTopicMap(): array
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ai' . DIRECTORY_SEPARATOR . 'formation_keywords.json';
        if (!is_file($path)) {
            return $this->getDefaultFormationKeywordTopicMap();
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || '' === trim($raw)) {
            return $this->getDefaultFormationKeywordTopicMap();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->getDefaultFormationKeywordTopicMap();
        }

        $normalizedMap = [];
        foreach ($decoded as $topic => $keywords) {
            $label = trim((string) $topic);
            if ('' === $label || !is_array($keywords)) {
                continue;
            }

            $cleanKeywords = [];
            foreach ($keywords as $keyword) {
                $value = trim((string) $keyword);
                if ('' === $value) {
                    continue;
                }

                $cleanKeywords[] = $value;
                if (count($cleanKeywords) >= 40) {
                    break;
                }
            }

            if ([] !== $cleanKeywords) {
                $normalizedMap[$label] = $cleanKeywords;
            }
        }

        return [] !== $normalizedMap ? $normalizedMap : $this->getDefaultFormationKeywordTopicMap();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function getDefaultFormationKeywordTopicMap(): array
    {
        return [
            'UI/UX' => ['ui ux', 'ux ui', 'design ui', 'design ux', 'user experience', 'experience utilisateur'],
            'Developpement Web' => ['dev web', 'developpement web', 'frontend', 'backend', 'full stack'],
            'Developpement Python' => ['python', 'django', 'flask', 'fastapi'],
            'Developpement Java' => ['java', 'spring', 'spring boot'],
            'DevOps' => ['devops', 'docker', 'kubernetes', 'ci cd', 'jenkins'],
            'Data Science' => ['data science', 'machine learning', 'deep learning', 'intelligence artificielle', 'ai'],
            'Marketing Digital' => ['marketing', 'marketing digital', 'seo', 'sea', 'social media'],
            'Ressources Humaines' => ['rh', 'ressources humaines', 'gestion rh', 'paie'],
            'Gestion de Projet' => ['gestion de projet', 'project management', 'scrum', 'agile', 'kanban'],
            'Salesforce' => ['salesforce', 'crm salesforce'],
            'Excel Avance' => ['excel', 'excel avance', 'tableau croise dynamique', 'vba'],
            'Cybersecurite' => ['cybersecurite', 'securite informatique', 'security', 'pentest'],
            'Certification' => ['certification', 'certif', 'iso 27001', 'pmp', 'itil', 'aws certified'],
        ];
    }

    private function cleanupTrainingNameCandidate(string $value): string
    {
        $clean = trim($value);
        $clean = preg_replace('/[\.,;:]+$/', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(?:je\s+reste\s+disponible|je\s+vous\s+remercie|merci|cordialement|avance\s+pour\s+votre\s+retour)\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(?:bonjour|salut|slt|je\s+veux|je\s+souhaite|je\s+demande)\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(?:transport|moyen\s+de\s+transport|deplacement|déplacement)\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(?:pour|vers|de|du|des|d[\'’]|a|à|afin|car|avec)\b.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/^(?:une|un|la|le|l[\'’])\s+/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/^(?:formation|certification)\s+/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        if ('' === $clean || $this->isLikelyLocationCandidate($clean)) {
            return '';
        }

        if (preg_match('/^(?:de|du|des|d[\'’])\s+[A-Za-zÀ-ÿ\'’\- ]{1,80}\s+vers\s+[A-Za-zÀ-ÿ\'’\- ]{1,80}$/iu', $clean) === 1) {
            return '';
        }

        $clean = preg_replace('/\s+(?:de|du|des|d[\'’])\s+[A-Za-zÀ-ÿ\'’\- ]{1,80}\s+vers\s+[A-Za-zÀ-ÿ\'’\- ]{1,80}\b.*$/iu', '', $clean) ?? $clean;
        $clean = trim($clean);

        if (strlen($clean) < 3) {
            return '';
        }

        return substr($clean, 0, 120);
    }

    private function extractOrganization(string $text): string
    {
        if (preg_match('/(?:organisme(?:\s+de\s+formation)?|chez)\s*[:\-]?\s*([A-Za-z0-9À-ÿ\'\-\s]{3,80})/iu', $text, $matches) === 1) {
            $value = trim((string) ($matches[1] ?? ''));
            $value = preg_replace('/\b(?:je\s+reste\s+disponible|je\s+vous\s+remercie|merci|cordialement|avance\s+pour\s+votre\s+retour)\b.*$/iu', '', $value) ?? $value;
            $value = trim((string) $value);

            return $value;
        }

        return '';
    }

    /**
     * @param array<int, string> $options
     */
    private function extractDurationLabel(array $options, string $text): string
    {
        foreach ($options as $option) {
            if (str_contains($text, strtolower($option))) {
                return $option;
            }
        }

        $months = $this->extractMonthDuration($text);
        if ($months > 0) {
            foreach ($options as $option) {
                if (str_contains(strtolower($option), (string) $months)) {
                    return $option;
                }
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $options
     */
    private function matchDaysPerWeekOption(array $options, string $text): string
    {
        if (str_contains($text, 'temps plein')) {
            return in_array('Temps plein', $options, true) ? 'Temps plein' : '';
        }

        if (preg_match('/\b(\d+)\s*jours?\b/i', $text, $matches) === 1) {
            $days = (int) ($matches[1] ?? 0);
            foreach ($options as $option) {
                if (str_contains(strtolower($option), (string) $days)) {
                    return $option;
                }
            }
        }

        return '';
    }

    private function extractWeekDays(string $text): string
    {
        $days = [];
        $map = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        foreach ($map as $day) {
            if (str_contains($text, $day)) {
                $days[] = ucfirst($day);
            }
        }

        return implode(', ', $days);
    }

    private function extractTimeWindow(string $text, string $key): string
    {
        preg_match_all('/\b(\d{1,2}[:h]\d{2}|\d{1,2}h)\b/i', $text, $matches);
        $times = array_values(array_unique(array_map(static fn($t) => str_replace('h', ':', strtolower((string) $t)), $matches[1] ?? [])));

        if ([] === $times) {
            return '';
        }

        return match ($key) {
            'heureDebut' => $times[0] ?? '',
            'heureFin' => $times[1] ?? '',
            'horairesActuels', 'horairesSouhaites' => implode(' - ', array_slice($times, 0, 2)),
            default => '',
        };
    }

    private function extractObjectDescription(string $text): string
    {
        return trim($text);
    }

    private function extractAccessZones(string $text): string
    {
        if (preg_match('/(?:zone|zones|acces a|acc[èe]s a)\s*[:\-]?\s*([A-Za-z0-9À-ÿ\'\-\s,]{3,100})/iu', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * @param array<int, string> $dates
     * @param array<int, string> $options
     */
    private function inferGenericFieldValue(
        string $key,
        string $type,
        string $label,
        array $options,
        string $text,
        string $normalizedText,
        array $dates,
        ?float $amount,
        string $location
    ): string {
        if ('date' === $type) {
            return $dates[0] ?? '';
        }

        if ('number' === $type) {
            if (null !== $amount && (str_contains(strtolower($key), 'montant') || str_contains(strtolower($label), 'cout'))) {
                return (string) $amount;
            }

            return $this->extractFirstInteger($normalizedText);
        }

        if ('location' === $type) {
            return $location;
        }

        if ('select' === $type) {
            foreach ($options as $option) {
                if (str_contains($normalizedText, strtolower($option))) {
                    return $option;
                }
            }
        }

        if ('textarea' === $type) {
            return $text;
        }

        if ('text' === $type && !str_contains(strtolower($key), 'email') && !str_contains(strtolower($key), 'telephone')) {
            return '';
        }

        return '';
    }
}
