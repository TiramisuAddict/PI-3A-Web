<?php

namespace App\Services;

use App\Entity\Demande;
use App\Repository\DemandeRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class DemandeDecisionAssistant
{
    public function __construct(
        private readonly DemandeRepository $demandeRepository,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey = '',
        private readonly string $model = '',
        private readonly int $timeoutSeconds = 20,
        private readonly string $pythonExecutable = 'python',
        private readonly string $pythonScriptPath = ''
    )
    {
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, array<string, mixed>> $fieldDefinitions
     * @return array<string, mixed>
     */
    public function analyze(Demande $demande, array $details, array $fieldDefinitions): array
    {
        $missingRequired = [];
        $weakRequired = [];
        $filledRequired = 0;
        $requiredCount = 0;
        $reasons = [];
        $warnings = [];
        $recommendedStatus = 'En cours';
        $confidence = 0.55;
        $qualityPenalty = 0.0;

        foreach ($fieldDefinitions as $field) {
            $key = trim((string) ($field['key'] ?? ''));
            $label = trim((string) ($field['label'] ?? $key));
            $required = true === ($field['required'] ?? false);
            $fieldType = trim((string) ($field['type'] ?? 'text'));

            if (!$required || '' === $key) {
                continue;
            }

            ++$requiredCount;
            $value = trim((string) ($details[$key] ?? ''));
            if ('' === $value) {
                $missingRequired[] = $label;
                continue;
            }

            ++$filledRequired;

            if ($this->isLowQualityValue($value, $key, $label, $fieldType)) {
                $weakRequired[] = $label;
            }
        }

        $meaningfulRequired = max(0, $filledRequired - count($weakRequired));
        $completeness = $requiredCount > 0
            ? round($meaningfulRequired / $requiredCount, 2)
            : 1.0;
        $missingSpecificCount = count($missingRequired) + count($weakRequired);

        if ([] !== $missingRequired) {
            $recommendedStatus = 'En attente';
            $confidence = match (true) {
                count($missingRequired) >= 4 => 0.18,
                count($missingRequired) === 3 => 0.26,
                count($missingRequired) === 2 => 0.34,
                default => 0.46,
            };
            $reasons[] = 'Des champs obligatoires sont manquants.';
        } else {
            $reasons[] = 'Les champs obligatoires principaux sont renseignes.';
            $confidence = 0.72;
        }

        if ([] !== $weakRequired) {
            $recommendedStatus = count($weakRequired) >= 2 ? 'Rejetee' : 'En attente';
            $confidence = min($confidence, count($weakRequired) >= 2 ? 0.24 : 0.38);
            $reasons[] = 'Certaines informations obligatoires semblent remplies avec un contenu trop faible ou non exploitable.';
            $warnings[] = 'Champs suspects: ' . implode(', ', array_slice($weakRequired, 0, 4));
            $qualityPenalty += min(0.35, 0.12 * count($weakRequired));
        }

        $type = trim((string) $demande->getTypeDemande());
        $normalizedType = $this->normalizeDecisionType($type);
        $title = trim((string) $demande->getTitre());
        $description = trim((string) $demande->getDescription());
        $descriptionLower = strtolower($description);
        $priorite = strtoupper(trim((string) $demande->getPriorite()));

        if ($this->isLowQualityValue($title, 'titre', 'Titre', 'text')) {
            $recommendedStatus = 'Rejetee';
            $confidence = min($confidence, 0.25);
            $reasons[] = 'Le titre de la demande est trop vague ou ressemble a un texte de test.';
            $warnings[] = 'Titre non fiable pour une decision automatique.';
            $qualityPenalty += 0.2;
        }

        if ($this->isLowQualityValue($description, 'description', 'Description', 'textarea')) {
            $recommendedStatus = 'Rejetee';
            $confidence = min($confidence, 0.22);
            $reasons[] = 'La description generale est trop pauvre, repetitive ou non exploitable.';
            $warnings[] = 'Description generale de faible qualite.';
            $qualityPenalty += 0.22;
        }

        if ('HAUTE' === $priorite) {
            $warnings[] = 'Priorite haute: verification humaine recommandee avant cloture.';
            if ([] === $missingRequired && [] === $weakRequired) {
                $confidence = min(0.95, $confidence + 0.03);
            }
        }

        switch ($normalizedType) {
            case 'remboursement':
                $this->analyzeRemboursement($details, $recommendedStatus, $reasons, $warnings, $confidence);
                break;
            case 'conge':
                $this->analyzeConge($details, $reasons, $warnings, $recommendedStatus, $confidence);
                break;
            case 'acces systeme':
                $this->analyzeAccesSysteme($details, $reasons, $warnings, $recommendedStatus, $confidence);
                break;
            case 'avance sur salaire':
                $this->analyzeAvanceSalaire($details, $reasons, $warnings, $recommendedStatus, $confidence);
                break;
            case 'teletravail':
                $this->analyzeTeletravail($details, $reasons, $warnings, $recommendedStatus, $confidence);
                break;
            case 'probleme technique':
                $this->analyzeProblemeTechnique($details, $reasons, $warnings, $recommendedStatus, $confidence);
                break;
            default:
                if ([] === $missingRequired && [] === $weakRequired && strlen($description) >= 20) {
                    $recommendedStatus = 'En cours';
                    $reasons[] = 'La demande est exploitable mais merite encore une verification metier.';
                    $confidence = max($confidence, 0.66);
                }
                break;
        }

        if ('autre' === $normalizedType && (strlen($description) < 15 || $this->isLowQualityValue($description, 'description', 'Description', 'textarea')) && ([] !== $missingRequired || [] !== $weakRequired)) {
            $recommendedStatus = 'Rejetee';
            $reasons[] = 'La demande est trop vague pour etre traitee en l etat.';
            $confidence = max($confidence, 0.84);
        }

        if ('Rejetee' !== $recommendedStatus && 'En attente' !== $recommendedStatus && [] === $missingRequired && [] === $weakRequired) {
            if ([] === $warnings) {
                $recommendedStatus = 'Resolue';
                $reasons[] = 'Aucun blocage majeur detecte dans les informations fournies.';
                $confidence = max($confidence, 0.8);
            } elseif ('En cours' !== $recommendedStatus) {
                $recommendedStatus = 'En cours';
            }
        }

        if ($qualityPenalty > 0) {
            $confidence = max(0.08, $confidence - $qualityPenalty);
        }

        if ([] !== $missingRequired) {
            $confidence = min($confidence, match (true) {
                count($missingRequired) >= 4 => 0.2,
                count($missingRequired) === 3 => 0.28,
                count($missingRequired) === 2 => 0.36,
                default => 0.48,
            });
        }

        if ($requiredCount > 0) {
            if ($completeness <= 0.25) {
                $confidence = min($confidence, 0.18);
            } elseif ($completeness <= 0.5) {
                $confidence = min($confidence, 0.3);
            } elseif ($completeness <= 0.75) {
                $confidence = min($confidence, 0.45);
            }
        }

        if ($missingSpecificCount >= 3) {
            $recommendedStatus = 'En attente';
            $reasons[] = 'Les informations specifiques de la demande sont trop incompletes pour une decision fiable.';
            $confidence = min($confidence, 0.24);
        } elseif ($missingSpecificCount === 2) {
            $recommendedStatus = 'En attente';
            $reasons[] = 'Plusieurs informations specifiques importantes sont absentes ou faibles.';
            $confidence = min($confidence, 0.34);
        }

        $spamScore = $this->calculateSpamScore($demande, $details, $weakRequired, $missingRequired);

        $repeatPenalty = $this->buildRepeatTypePenalty($demande);
        if ($repeatPenalty['count'] > 0) {
            $confidence = max(0.08, $confidence - $repeatPenalty['confidencePenalty']);
            $spamScore = min(100, $spamScore + $repeatPenalty['spamPenalty']);

            $reasons[] = sprintf(
                'Des demandes de meme type (%s) ont ete creees recemment par le meme employe (%d sur %d jours).',
                (string) $demande->getTypeDemande(),
                $repeatPenalty['count'],
                $repeatPenalty['windowDays']
            );
            $warnings[] = 'Risque de demande repetitive: la priorisation automatique est volontairement reduite.';

            if ($repeatPenalty['count'] >= 2 && 'Rejetee' !== $recommendedStatus) {
                $recommendedStatus = 'En attente';
            }
        }

        $mlSignals = $this->fetchDecisionModelSignals($demande, $details, $fieldDefinitions);
        if (null !== $mlSignals) {
            // Keep legacy rule-based behavior as primary and blend in ML signals lightly.
            $confidence = round(($confidence * 0.85) + ($mlSignals['confidence'] * 0.15), 2);
            $spamScore = (int) round(min(100, max(0, ($spamScore * 0.85) + ($mlSignals['spamScore'] * 0.15))));

            if ('' !== $mlSignals['note']) {
                $warnings[] = 'Signal ML: ' . $mlSignals['note'];
            }
        }

        $reasons = $this->finalizeReasons(
            $reasons,
            $recommendedStatus,
            $missingRequired,
            $weakRequired,
            $repeatPenalty
        );
        $spamLevel = $this->getSpamLevel($spamScore);

        return [
            'recommendedStatus' => $recommendedStatus,
            'confidence' => round($confidence, 2),
            'completeness' => $completeness,
            'missingRequired' => $missingRequired,
            'weakRequired' => $weakRequired,
            'spamScore' => $spamScore,
            'spamLevel' => $spamLevel,
            'reasons' => array_values(array_unique($reasons)),
            'warnings' => array_values(array_unique($warnings)),
            'summary' => $this->buildSummary($recommendedStatus, $missingRequired, $weakRequired, $warnings),
            'repeatTypePenalty' => $repeatPenalty,
            'mlSignals' => $mlSignals,
        ];
    }

    /**
     * @return array{count:int,windowDays:int,confidencePenalty:float,spamPenalty:int}
     */
    private function buildRepeatTypePenalty(Demande $demande): array
    {
        $employeId = $demande->getEmploye()?->getId_employe();
        $typeDemande = trim((string) $demande->getTypeDemande());
        $dateCreation = $demande->getDateCreation();
        $demandeId = $demande->getIdDemande();

        if (0 === strcasecmp($typeDemande, 'Autre')) {
            return [
                'count' => 0,
                'windowDays' => 7,
                'confidencePenalty' => 0.0,
                'spamPenalty' => 0,
            ];
        }

        if (null === $employeId || '' === $typeDemande || null === $dateCreation) {
            return [
                'count' => 0,
                'windowDays' => 7,
                'confidencePenalty' => 0.0,
                'spamPenalty' => 0,
            ];
        }

        $windowDays = 7;
        $recentCount = $this->demandeRepository->countRecentSameTypeForEmploye(
            (int) $employeId,
            $typeDemande,
            $dateCreation,
            $windowDays,
            $demandeId
        );

        if ($recentCount <= 0) {
            return [
                'count' => 0,
                'windowDays' => $windowDays,
                'confidencePenalty' => 0.0,
                'spamPenalty' => 0,
            ];
        }

        // Progressive penalty: each additional nearby same-type demande lowers ranking for this demande only.
        $confidencePenalty = min(0.32, 0.08 + (($recentCount - 1) * 0.06));
        $spamPenalty = min(35, 10 + (($recentCount - 1) * 8));

        return [
            'count' => $recentCount,
            'windowDays' => $windowDays,
            'confidencePenalty' => round($confidencePenalty, 2),
            'spamPenalty' => $spamPenalty,
        ];
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, array<string, mixed>> $fieldDefinitions
     * @return array{confidence:float,spamScore:int,risk:string,note:string}|null
     */
    private function fetchDecisionModelSignals(Demande $demande, array $details, array $fieldDefinitions): ?array
    {
        if ('' === trim($this->pythonScriptPath) || !is_file($this->pythonScriptPath)) {
            return null;
        }

        $payload = [
            'demande' => [
                'id' => $demande->getIdDemande(),
                'categorie' => (string) $demande->getCategorie(),
                'typeDemande' => (string) $demande->getTypeDemande(),
                'titre' => (string) $demande->getTitre(),
                'description' => (string) $demande->getDescription(),
                'priorite' => (string) $demande->getPriorite(),
                'status' => (string) $demande->getStatus(),
                'dateCreation' => $demande->getDateCreation()?->format('Y-m-d'),
            ],
            'details' => $details,
            'fieldDefinitions' => $fieldDefinitions,
            'trainingSamples' => $this->fetchDecisionTrainingSamples(),
        ];

        $lastError = '';
        foreach ($this->getPythonCommandCandidates() as $commandPrefix) {
            try {
                $process = new Process(array_merge($commandPrefix, [$this->pythonScriptPath]));
                $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if (false === $encodedPayload) {
                    throw new \RuntimeException('Unable to encode JSON payload for Python decision runner.');
                }
                $process->setInput($encodedPayload);
                $process->setTimeout($this->timeoutSeconds + 5);
                $process->run();

                if (!$process->isSuccessful()) {
                    $lastError = trim($process->getErrorOutput() ?: $process->getOutput());
                    continue;
                }

                $decoded = json_decode(trim($process->getOutput()), true);
                if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
                    $lastError = 'Decision ML Python runner returned invalid payload.';
                    continue;
                }

                $signals = $decoded['signals'] ?? null;
                if (!is_array($signals)) {
                    $lastError = 'Decision ML Python runner returned missing signals.';
                    continue;
                }

                $confidence = (float) ($signals['confidence'] ?? 0);
                $spamScore = (int) ($signals['spamScore'] ?? 0);
                $risk = trim((string) ($signals['risk'] ?? ''));
                $note = trim((string) ($signals['note'] ?? ''));

                return [
                    'confidence' => max(0.0, min(1.0, $confidence)),
                    'spamScore' => max(0, min(100, $spamScore)),
                    'risk' => $risk,
                    'note' => $note,
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $this->logger->warning('Decision ML Python runner unavailable.', [
            'commands' => array_map(static fn(array $cmd): string => implode(' ', $cmd), $this->getPythonCommandCandidates()),
            'error' => $lastError,
        ]);

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function fetchDecisionTrainingSamples(): array
    {
        try {
            return $this->demandeRepository->fetchDecisionTrainingSamples(1800);
        } catch (\Throwable $e) {
            $this->logger->warning('Decision ML training samples unavailable.', ['exception' => $e->getMessage()]);
            return [];
        }
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

        foreach ($this->getLocalProjectPythonExecutables() as $pythonPath) {
            $candidates[] = [$pythonPath];
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
     * @return array<int, string>
     */
    private function getLocalProjectPythonExecutables(): array
    {
        $root = dirname(__DIR__, 2);
        $candidates = [];

        if ('\\' === DIRECTORY_SEPARATOR) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
            $candidates[] = $root . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        } else {
            $candidates[] = $root . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python3';
            $candidates[] = $root . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
            $candidates[] = $root . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python3';
            $candidates[] = $root . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
        }

        return array_values(array_filter(array_unique($candidates), static fn (string $path): bool => is_file($path)));
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $reasons
     * @param array<int, string> $warnings
     */
    private function analyzeRemboursement(array $details, string &$recommendedStatus, array &$reasons, array &$warnings, float &$confidence): void
    {
        $justificatif = strtolower(trim((string) ($details['justificatif'] ?? '')));
        $amount = (float) ($details['montant'] ?? 0);

        if (str_contains($justificatif, 'non')) {
            $recommendedStatus = 'En attente';
            $reasons[] = 'Le justificatif est manquant ou annonce comme a fournir.';
            $confidence = max($confidence, 0.9);
        }

        if ($amount > 1000) {
            $warnings[] = 'Montant eleve: validation humaine recommandee.';
            $recommendedStatus = 'En cours';
        }
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $reasons
     * @param array<int, string> $warnings
     */
    private function analyzeConge(array $details, array &$reasons, array &$warnings, string &$recommendedStatus, float &$confidence): void
    {
        $start = trim((string) ($details['dateDebut'] ?? ''));
        $end = trim((string) ($details['dateFin'] ?? ''));
        $days = (int) ($details['nombreJours'] ?? 0);

        if ('' !== $start && '' !== $end && $start > $end) {
            $recommendedStatus = 'Rejetee';
            $reasons[] = 'La date de fin est incoherente avec la date de debut.';
            $confidence = max($confidence, 0.93);
        }

        if ($days > 20) {
            $warnings[] = 'Conge longue duree: verification RH recommandee.';
            $recommendedStatus = 'En cours';
        }
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $reasons
     * @param array<int, string> $warnings
     */
    private function analyzeAccesSysteme(array $details, array &$reasons, array &$warnings, string &$recommendedStatus, float &$confidence): void
    {
        $typeAcces = strtolower(trim((string) ($details['typeAcces'] ?? '')));
        $justification = trim((string) ($details['justification'] ?? ''));

        if ('' === $justification || strlen($justification) < 12) {
            $recommendedStatus = 'En attente';
            $reasons[] = 'La justification de l acces est trop courte ou absente.';
            $confidence = max($confidence, 0.86);
        }

        if (str_contains($typeAcces, 'admin')) {
            $warnings[] = 'Acces administrateur demande: validation manuelle indispensable.';
            $recommendedStatus = 'En cours';
        }
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $reasons
     * @param array<int, string> $warnings
     */
    private function analyzeAvanceSalaire(array $details, array &$reasons, array &$warnings, string &$recommendedStatus, float &$confidence): void
    {
        $amount = (float) ($details['montant'] ?? 0);
        $motif = trim((string) ($details['motif'] ?? ''));

        if ($amount <= 0) {
            $recommendedStatus = 'Rejetee';
            $reasons[] = 'Le montant demande est invalide.';
            $confidence = max($confidence, 0.91);
        }

        if (strlen($motif) < 20) {
            $recommendedStatus = 'En attente';
            $reasons[] = 'Le motif financier manque de precision.';
            $confidence = max($confidence, 0.83);
        }

        if ($amount > 2000) {
            $warnings[] = 'Montant eleve: arbitrage RH/finance conseille.';
            $recommendedStatus = 'En cours';
        }
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $reasons
     * @param array<int, string> $warnings
     */
    private function analyzeTeletravail(array $details, array &$reasons, array &$warnings, string &$recommendedStatus, float &$confidence): void
    {
        $address = trim((string) ($details['adresseTeletravail'] ?? ''));
        $days = trim((string) ($details['joursParSemaine'] ?? ''));

        if ('' === $address) {
            $recommendedStatus = 'En attente';
            $reasons[] = 'L adresse de teletravail n est pas renseignee.';
            $confidence = max($confidence, 0.84);
        }

        if (str_contains(strtolower($days), 'temps plein')) {
            $warnings[] = 'Teletravail temps plein: validation manager recommandee.';
            $recommendedStatus = 'En cours';
        }
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $reasons
     * @param array<int, string> $warnings
     */
    private function analyzeProblemeTechnique(array $details, array &$reasons, array &$warnings, string &$recommendedStatus, float &$confidence): void
    {
        $impact = strtolower(trim((string) ($details['impact'] ?? '')));
        $description = trim((string) ($details['descriptionProbleme'] ?? ''));

        if ('' === $description || strlen($description) < 12) {
            $recommendedStatus = 'En attente';
            $reasons[] = 'La description du probleme est insuffisante.';
            $confidence = max($confidence, 0.82);
        }

        if (str_contains($impact, 'bloquant')) {
            $warnings[] = 'Incident bloquant: priorisation immediate recommandee.';
            $recommendedStatus = 'En cours';
        }
    }

    /**
     * @param array<int, string> $missingRequired
     * @param array<int, string> $warnings
     */
    private function buildSummary(string $recommendedStatus, array $missingRequired, array $weakRequired, array $warnings): string
    {
        return match ($recommendedStatus) {
            'Resolue' => 'La demande parait complete et traitable sans blocage majeur.',
            'Rejetee' => [] !== $weakRequired
                ? 'Le contenu parait trop faible, repetitif ou proche d un texte de test pour etre accepte.'
                : 'La demande contient une incoherence ou est trop insuffisante pour etre acceptee.',
            'En attente' => [] !== $missingRequired
                ? 'Des informations manquent. Il vaut mieux demander un complement avant decision.'
                : ([] !== $weakRequired
                    ? 'Les informations presentes existent mais ne sont pas assez fiables ou precises.'
                    : 'La demande necessite une confirmation ou une piece complementaire.'),
            default => [] !== $warnings
                ? 'La demande est exploitable mais requiert une verification humaine.'
                : 'La demande peut avancer vers une prise en charge.',
        };
    }

    private function isLowQualityValue(string $value, string $key = '', string $label = '', string $fieldType = 'text'): bool
    {
        $text = trim(mb_strtolower($value));
        if ('' === $text) {
            return true;
        }

        $context = mb_strtolower($key . ' ' . $label . ' ' . $fieldType);

        if ('number' === $fieldType || preg_match('/^\d+(?:[.,]\d+)?$/', $text) === 1) {
            return false;
        }

        if ('date' === $fieldType || preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return false;
        }

        if ('select' === $fieldType) {
            return false;
        }

        if (
            str_contains($context, 'nombre') ||
            str_contains($context, 'quantite') ||
            str_contains($context, 'montant') ||
            str_contains($context, 'cout') ||
            str_contains($context, 'date') ||
            str_contains($context, 'jourspar') ||
            str_contains($context, 'periode')
        ) {
            return false;
        }

        if (mb_strlen($text) < 4) {
            return true;
        }

        if (preg_match('/^(a|b|c|d|e|x|z|1|0|\?|\.)\1{2,}$/u', $text) === 1) {
            return true;
        }

        if (preg_match('/^(test|aaaa+|bbbb+|cccc+|dddd+|xxxxx+|qsdf+|azerty+|hjkl+|demo|tmp)$/u', $text) === 1) {
            return true;
        }

        $lettersOnly = preg_replace('/[^a-zà-ÿ]/u', '', $text) ?? '';
        $uniqueChars = count(array_unique(preg_split('//u', $lettersOnly, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if ('' !== $lettersOnly && mb_strlen($lettersOnly) >= 4 && $uniqueChars <= 2) {
            return true;
        }

        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] !== $tokens) {
            $uniqueTokens = count(array_unique($tokens));
            if (count($tokens) >= 2 && $uniqueTokens <= 1) {
                return true;
            }
        }

        if (
            (str_contains($context, 'motif') || str_contains($context, 'description') || str_contains($context, 'justification') || str_contains($context, 'titre')) &&
            mb_strlen($text) < 12
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $details
     * @param array<int, string> $weakRequired
     * @param array<int, string> $missingRequired
     */
    private function calculateSpamScore(Demande $demande, array $details, array $weakRequired, array $missingRequired): int
    {
        $score = 0;

        if ($this->isLowQualityValue((string) $demande->getTitre(), 'titre', 'Titre', 'text')) {
            $score += 30;
        }

        if ($this->isLowQualityValue((string) $demande->getDescription(), 'description', 'Description', 'textarea')) {
            $score += 35;
        }

        $score += min(30, count($weakRequired) * 12);
        $score += min(20, count($missingRequired) * 6);

        foreach ($details as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            if ($this->isLowQualityValue((string) $value, (string) $key, (string) $key, 'text')) {
                $score += 6;
            }
        }

        return min(100, $score);
    }

    /**
     * @return array<string, string|int>
     */
    private function getSpamLevel(int $score): array
    {
        if ($score >= 70) {
            return [
                'label' => 'Eleve',
                'tone' => 'danger',
                'description' => 'La demande ressemble fortement a un test ou a un contenu non exploitable.',
            ];
        }

        if ($score >= 40) {
            return [
                'label' => 'Moyen',
                'tone' => 'warning',
                'description' => 'Plusieurs signaux montrent un contenu peu fiable ou trop faible.',
            ];
        }

        return [
            'label' => 'Faible',
            'tone' => 'success',
            'description' => 'Le contenu ne presente pas de signal fort de test ou de spam.',
        ];
    }

    /**
     * @param array<int, string> $reasons
     * @param array<int, string> $missingRequired
     * @param array<int, string> $weakRequired
     * @param array{count:int,windowDays:int,confidencePenalty:float,spamPenalty:int} $repeatPenalty
     * @return array<int, string>
     */
    private function finalizeReasons(
        array $reasons,
        string $recommendedStatus,
        array $missingRequired,
        array $weakRequired,
        array $repeatPenalty
    ): array {
        $positiveReason = 'Les champs obligatoires principaux sont renseignes.';
        $uniqueReasons = array_values(array_unique($reasons));

        if (
            in_array($recommendedStatus, ['En attente', 'Rejetee'], true)
            || [] !== $missingRequired
            || [] !== $weakRequired
            || ($repeatPenalty['count'] ?? 0) > 0
        ) {
            $uniqueReasons = array_values(array_filter(
                $uniqueReasons,
                static fn(string $reason): bool => $reason !== $positiveReason
            ));
        }

        if (count($uniqueReasons) > 1 && in_array($positiveReason, $uniqueReasons, true)) {
            $uniqueReasons = array_values(array_filter(
                $uniqueReasons,
                static fn(string $reason): bool => $reason !== $positiveReason
            ));
        }

        if ([] === $uniqueReasons && 'Resolue' === $recommendedStatus) {
            $uniqueReasons[] = $positiveReason;
        }

        return $uniqueReasons;
    }

    private function normalizeDecisionType(string $value): string
    {
        $normalized = trim(mb_strtolower($value));
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (false !== $ascii) {
                $normalized = strtolower($ascii);
            }
        }

        $normalized = str_replace(['-', '_', '/'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $normalized;
    }
}
