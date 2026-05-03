<?php

namespace App\Command;

use App\Entity\Demande;
use App\Entity\DemandeDetail;
use App\Repository\DemandeRepository;
use App\Service\DemandeAiAssistant;
use App\Service\DemandeFormHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill-demande-details',
    description: 'Normalise les categories/types importes et migre les anciens details vers le schema actuel.'
)]
class BackfillDemandeDetailsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemandeRepository $demandeRepository,
        private readonly DemandeFormHelper $formHelper,
        private readonly DemandeAiAssistant $aiAssistant
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Ecrit les changements en base.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de demandes a traiter.', '500')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Traiter une seule demande par identifiant.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $singleId = $input->getOption('id');
        $limit = max(1, (int) $input->getOption('limit'));

        $qb = $this->demandeRepository->createQueryBuilder('d')
            ->leftJoin('d.demandeDetails', 'dd')
            ->addSelect('dd')
            ->orderBy('d.id_demande', 'ASC')
            ->setMaxResults($limit);

        if (null !== $singleId && '' !== (string) $singleId) {
            $qb->andWhere('d.id_demande = :id')->setParameter('id', (int) $singleId);
        }

        /** @var list<Demande> $demandes */
        $demandes = $qb->getQuery()->getResult();
        if ([] === $demandes) {
            $io->success('Aucune demande a traiter.');
            return Command::SUCCESS;
        }

        $stats = [
            'scanned' => 0,
            'changed' => 0,
            'created_details' => 0,
            'updated_category' => 0,
            'updated_type' => 0,
            'updated_payload' => 0,
        ];

        foreach ($demandes as $demande) {
            ++$stats['scanned'];

            $originalCategory = $demande->getCategorie();
            $originalType = $demande->getTypeDemande();
            $canonicalType = $this->formHelper->resolveCanonicalType($originalType, $originalCategory) ?? $originalType;
            $canonicalCategory = $this->formHelper->resolveCanonicalCategory($originalCategory, $canonicalType) ?? $originalCategory;

            $detailEntity = $demande->getDemandeDetails()->first();
            if (!$detailEntity instanceof DemandeDetail) {
                $detailEntity = new DemandeDetail();
                $detailEntity->setDemande($demande);
                $detailEntity->setDetails('{}');
                $demande->addDemandeDetail($detailEntity);
                ++$stats['created_details'];
                if ($apply) {
                    $this->em->persist($detailEntity);
                }
            }

            $rawDetails = $detailEntity->getDetails();
            $currentDetails = json_decode($rawDetails, true);
            if (!is_array($currentDetails)) {
                $currentDetails = [];
            }

            $migratedDetails = $this->migrateDetails($demande, $canonicalType, $currentDetails);
            $payloadChanged = $this->normalizeForCompare($currentDetails) !== $this->normalizeForCompare($migratedDetails);
            $entityChanged = false;

            if ($canonicalCategory !== $originalCategory) {
                $demande->setCategorie($canonicalCategory);
                ++$stats['updated_category'];
                $entityChanged = true;
            }

            if ($canonicalType !== $originalType) {
                $demande->setTypeDemande($canonicalType);
                ++$stats['updated_type'];
                $entityChanged = true;
            }

            if ($payloadChanged) {
                $detailEntity->setDetails((string) json_encode($migratedDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                ++$stats['updated_payload'];
                $entityChanged = true;
            }

            if ($entityChanged) {
                ++$stats['changed'];
                if ($apply) {
                    $this->em->persist($demande);
                    $this->em->persist($detailEntity);
                }

                $io->writeln(sprintf(
                    '#%d [%s / %s] %s',
                    (int) $demande->getIdDemande(),
                    $canonicalCategory,
                    $canonicalType,
                    $apply ? 'mis a jour' : 'serait mis a jour'
                ));
            }
        }

        if ($apply) {
            $this->em->flush();
            $io->success('Backfill termine et enregistre en base.');
        } else {
            $io->note('Dry-run termine. Relancez avec --apply pour enregistrer.');
        }

        $io->table(
            ['Mesure', 'Valeur'],
            [
                ['Demandes scannees', $stats['scanned']],
                ['Demandes modifiees', $stats['changed']],
                ['Details crees', $stats['created_details']],
                ['Categories normalisees', $stats['updated_category']],
                ['Types normalises', $stats['updated_type']],
                ['Payloads details mis a jour', $stats['updated_payload']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateDetails(Demande $demande, string $canonicalType, array $details): array
    {
        $normalized = $details;

        switch ($canonicalType) {
            case 'Remboursement':
                $normalized = $this->migrateRemboursementDetails($demande, $normalized);
                break;
            case 'Avance sur salaire':
                $normalized = $this->migrateAvanceDetails($normalized);
                break;
            case 'Teletravail':
                $normalized = $this->migrateTeletravailDetails($demande, $normalized);
                break;
            case 'Acces systeme':
                $normalized = $this->migrateAccesSystemeDetails($normalized);
                break;
            case 'Conge':
                $normalized = $this->migrateCongeDetails($normalized);
                break;
            case 'Formation externe':
                $normalized = $this->migrateFormationExterneDetails($demande, $normalized);
                break;
            case 'Formation interne':
                $normalized = $this->migrateFormationInterneDetails($demande, $normalized);
                break;
            case 'Certification':
                $normalized = $this->migrateCertificationDetails($demande, $normalized);
                break;
        }

        $fields = $this->formHelper->getFieldsForType($canonicalType);
        if ([] === $fields) {
            return $normalized;
        }

        $sourceText = trim(implode(' ', array_filter([
            $demande->getTitre(),
            $demande->getDescription(),
            $this->flattenScalarDetails($details),
        ], function ($v) {
            return '' !== trim($v);
        })));

        $suggested = $this->aiAssistant->extractSuggestedDetailsForType($sourceText, $canonicalType, $fields);
        foreach ($suggested as $key => $value) {
            if (!$this->hasMeaningfulValue($normalized[$key] ?? null) && $this->hasMeaningfulValue($value)) {
                $normalized[$key] = $value;
            }
        }

        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            $required = (bool) ($field['required'] ?? false);

            if ('' === $key || !$required || $this->hasMeaningfulValue($normalized[$key] ?? null)) {
                continue;
            }

            $fallback = $this->buildRequiredFallback($demande, $canonicalType, $field, $normalized);
            if (null !== $fallback && '' !== trim($fallback)) {
                $normalized[$key] = $fallback;
            }

            if ('date' === $type && !$this->hasMeaningfulValue($normalized[$key] ?? null)) {
                $normalized[$key] = $demande->getDateCreation()->format('Y-m-d');
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateRemboursementDetails(Demande $demande, array $details): array
    {
        if (!$this->hasMeaningfulValue($details['typeRemboursement'] ?? null)) {
            $details['typeRemboursement'] = 'Frais de transport';
        }

        if (isset($details['montant']) && is_string($details['montant'])) {
            $details['montant'] = str_replace(',', '.', $details['montant']);
        }

        if (!$this->hasMeaningfulValue($details['dateDepense'] ?? null)) {
            $details['dateDepense'] = $demande->getDateCreation()->format('Y-m-d');
        }

        if (!$this->hasMeaningfulValue($details['justificatif'] ?? null)) {
            $details['justificatif'] = 'Oui';
        }

        if (!$this->hasMeaningfulValue($details['details'] ?? null)) {
            $parts = [];
            if ($this->hasMeaningfulValue($details['lieuDepart'] ?? null) || $this->hasMeaningfulValue($details['destinationSouhaitee'] ?? null)) {
                $parts[] = 'Trajet: ' . trim((string) ($details['lieuDepart'] ?? '')) . ' vers ' . trim((string) ($details['destinationSouhaitee'] ?? ''));
            }
            if ($this->hasMeaningfulValue($details['moyenTransport'] ?? null)) {
                $parts[] = 'Transport: ' . trim((string) $details['moyenTransport']);
            }
            if ($this->hasMeaningfulValue($details['motifTransport'] ?? null)) {
                $parts[] = 'Motif: ' . trim((string) $details['motifTransport']);
            }
            if ([] !== $parts) {
                $details['details'] = implode('. ', $parts) . '.';
            }
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateAvanceDetails(array $details): array
    {
        if (!$this->hasMeaningfulValue($details['modaliteRemboursement'] ?? null) && $this->hasMeaningfulValue($details['remboursement'] ?? null)) {
            $details['modaliteRemboursement'] = (string) $details['remboursement'];
        }

        if (isset($details['montant']) && is_string($details['montant'])) {
            $details['montant'] = str_replace(',', '.', $details['montant']);
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateTeletravailDetails(Demande $demande, array $details): array
    {
        if (!$this->hasMeaningfulValue($details['typeTeletravail'] ?? null)) {
            $periode = strtolower(trim((string) ($details['periode'] ?? '')));
            $details['typeTeletravail'] = str_contains($periode, 'hebdo') ? 'Teletravail regulier' : 'Teletravail occasionnel';
        }

        if (!$this->hasMeaningfulValue($details['joursSouhaites'] ?? null) && $this->hasMeaningfulValue($details['jours'] ?? null)) {
            $details['joursSouhaites'] = str_replace(',', ', ', (string) $details['jours']);
        }

        if (!$this->hasMeaningfulValue($details['joursParSemaine'] ?? null) && $this->hasMeaningfulValue($details['jours'] ?? null)) {
            $count = count(array_filter(array_map('trim', explode(',', (string) $details['jours'])), fn($v) => '' !== $v));
            if ($count >= 1 && $count <= 4) {
                $details['joursParSemaine'] = $count . ' jour' . ($count > 1 ? 's' : '');
            }
        }

        if (!$this->hasMeaningfulValue($details['motifTeletravail'] ?? null) && $this->hasMeaningfulValue($details['motif'] ?? null)) {
            $details['motifTeletravail'] = (string) $details['motif'];
        }

        if (!$this->hasMeaningfulValue($details['adresseTeletravail'] ?? null)) {
            $details['adresseTeletravail'] = 'Domicile employe';
        }

        if (!$this->hasMeaningfulValue($details['dateDebutTeletravail'] ?? null)) {
            $details['dateDebutTeletravail'] = $demande->getDateCreation()->format('Y-m-d');
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateAccesSystemeDetails(array $details): array
    {
        if (!$this->hasMeaningfulValue($details['typeAcces'] ?? null) && $this->hasMeaningfulValue($details['niveau'] ?? null)) {
            $niveau = strtolower(trim((string) $details['niveau']));
            $details['typeAcces'] = match (true) {
                str_contains($niveau, 'admin') => 'Administrateur',
                str_contains($niveau, 'lecture') => 'Lecture seule',
                default => 'Lecture/Ecriture',
            };
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateCongeDetails(array $details): array
    {
        if (!$this->hasMeaningfulValue($details['typeConge'] ?? null) && $this->hasMeaningfulValue($details['motif'] ?? null)) {
            $motif = strtolower(trim((string) $details['motif']));
            $details['typeConge'] = str_contains($motif, 'malad') ? 'Conge maladie' : 'Conge annuel';
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateFormationExterneDetails(Demande $demande, array $details): array
    {
        $title = trim($demande->getTitre());
        $suggestedName = $this->extractTrainingLabelFromTitle($title, 'formation');

        if ($this->isSuspiciousTrainingValue($details['nomFormationExt'] ?? null) || !$this->hasMeaningfulValue($details['nomFormationExt'] ?? null)) {
            $details['nomFormationExt'] = $suggestedName !== '' ? $suggestedName : 'Formation externe';
        }

        if ($this->isSuspiciousOrganizationValue($details['organisme'] ?? null) || !$this->hasMeaningfulValue($details['organisme'] ?? null)) {
            $details['organisme'] = 'A confirmer';
        }

        if ($this->isSuspiciousDurationValue($details['duree'] ?? null, $title) || !$this->hasMeaningfulValue($details['duree'] ?? null)) {
            $details['duree'] = 'A confirmer';
        }

        if ($this->isGenericObjectiveValue($details['objectif'] ?? null) || !$this->hasMeaningfulValue($details['objectif'] ?? null)) {
            $details['objectif'] = 'Suivre la formation "' . ($details['nomFormationExt'] ?? 'Formation externe') . '" afin de developper des competences utiles au service.';
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateFormationInterneDetails(Demande $demande, array $details): array
    {
        $title = trim($demande->getTitre());
        $suggestedName = $this->extractTrainingLabelFromTitle($title, 'formation');

        if ($this->isSuspiciousTrainingValue($details['nomFormation'] ?? null) || !$this->hasMeaningfulValue($details['nomFormation'] ?? null)) {
            $details['nomFormation'] = $suggestedName !== '' ? $suggestedName : 'Formation interne';
        }

        if ($this->isSuspiciousOrganizationValue($details['formateur'] ?? null) || !$this->hasMeaningfulValue($details['formateur'] ?? null)) {
            $details['formateur'] = 'A confirmer';
        }

        if ($this->isGenericObjectiveValue($details['objectifFormation'] ?? null) || !$this->hasMeaningfulValue($details['objectifFormation'] ?? null)) {
            $details['objectifFormation'] = 'Suivre la formation "' . ($details['nomFormation'] ?? 'Formation interne') . '" afin de renforcer les competences metier.';
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function migrateCertificationDetails(Demande $demande, array $details): array
    {
        $title = trim($demande->getTitre());
        $suggestedName = $this->extractTrainingLabelFromTitle($title, 'certification');

        if ($this->isSuspiciousTrainingValue($details['nomCertification'] ?? null) || !$this->hasMeaningfulValue($details['nomCertification'] ?? null)) {
            $details['nomCertification'] = $suggestedName !== '' ? $suggestedName : 'Certification';
        }

        if ($this->isSuspiciousOrganizationValue($details['organismeCertif'] ?? null) || !$this->hasMeaningfulValue($details['organismeCertif'] ?? null)) {
            $details['organismeCertif'] = 'A confirmer';
        }

        if ($this->isGenericObjectiveValue($details['justificationCertif'] ?? null) || !$this->hasMeaningfulValue($details['justificationCertif'] ?? null)) {
            $details['justificationCertif'] = 'Obtenir la certification "' . ($details['nomCertification'] ?? 'Certification') . '" afin de renforcer les competences techniques et les besoins du service.';
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $details
     */
    private function buildRequiredFallback(Demande $demande, string $canonicalType, array $field, array $details): ?string
    {
        $key = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        $options = isset($field['options']) && is_array($field['options']) ? array_values(array_map('strval', $field['options'])) : [];

        if ('select' === $type && [] !== $options) {
            return $options[0];
        }

        $title = $demande->getTitre();
        $description = $demande->getDescription();
        $descriptionFallback = '' !== trim($description) ? $description : $title;

        return match ($key) {
            'justification', 'justificationLogiciel', 'justificationCertif' => $descriptionFallback,
            'descriptionProbleme', 'motif', 'motifTeletravail', 'details', 'objectif', 'objectifFormation' => $descriptionFallback,
            'systeme', 'nomLogiciel', 'nomFormationExt', 'nomFormation', 'nomCertification' => '' !== trim($title) ? $title : $canonicalType,
            'lieuFormation', 'lieuExamen', 'lieuMutation', 'adresseTeletravail' => 'A confirmer',
            default => ('text' === $type || 'textarea' === $type) ? ('' !== trim($title) ? $title : $canonicalType) : null,
        };
    }

    /**
     * @param array<string, mixed> $details
     */
    private function flattenScalarDetails(array $details): string
    {
        $parts = [];
        foreach ($details as $value) {
            if (is_scalar($value) && '' !== trim((string) $value)) {
                $parts[] = trim((string) $value);
            }
        }

        return implode(' ', $parts);
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }

        if (is_string($value)) {
            return '' !== trim($value);
        }

        if (is_numeric($value)) {
            return true;
        }

        return false;
    }

    private function isSuspiciousTrainingValue(mixed $value): bool
    {
        $text = trim((string) ($value ?? ''));
        if ('' === $text) {
            return true;
        }

        $normalized = $this->normalizeLooseText($text);
        return str_starts_with($normalized, 'du ')
            || str_starts_with($normalized, 'de ')
            || str_contains($normalized, 'demande de transport')
            || str_contains($normalized, 'avance pour votre retour')
            || str_contains($normalized, 'bonjour je souhaite soumettre');
    }

    private function isSuspiciousOrganizationValue(mixed $value): bool
    {
        $text = trim((string) ($value ?? ''));
        if ('' === $text) {
            return true;
        }

        $normalized = $this->normalizeLooseText($text);
        return str_contains($normalized, 'avance pour votre retour')
            || str_contains($normalized, 'je vous remercie')
            || str_contains($normalized, 'bonjour je souhaite')
            || in_array($normalized, ['formation', 'formation externe', 'certification'], true);
    }

    private function isSuspiciousDurationValue(mixed $value, string $title): bool
    {
        $text = trim((string) ($value ?? ''));
        if ('' === $text) {
            return true;
        }

        $normalized = $this->normalizeLooseText($text);
        $normalizedTitle = $this->normalizeLooseText($title);
        if ($normalized === $normalizedTitle) {
            return true;
        }

        return preg_match('/^\d+\s+(jour|jours|semaine|semaines|mois|heure|heures)$/', $normalized) !== 1;
    }

    private function isGenericObjectiveValue(mixed $value): bool
    {
        $text = trim((string) ($value ?? ''));
        if ('' === $text) {
            return true;
        }

        $normalized = $this->normalizeLooseText($text);
        return str_starts_with($normalized, 'bonjour je souhaite soumettre une demande liee')
            || str_contains($normalized, 'je reste disponible pour tout complement')
            || str_contains($normalized, 'je vous remercie par avance');
    }

    private function extractTrainingLabelFromTitle(string $title, string $kind): string
    {
        $candidate = trim($title);
        if ('' === $candidate) {
            return '';
        }

        $candidate = preg_replace('/^demande\s+de\s+transport\s+pour\s+une?\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^demande\s+de\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^(formation|certification)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+du\s+[A-Za-zÀ-ÿ\'\-\s]{2,80}\s+vers\s+[A-Za-zÀ-ÿ\'\-\s]{2,80}(?:\s+le\s+\d{1,2}\s+\w+)?$/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+le\s+\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}$/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+le\s+\d{1,2}\s+\w+$/iu', '', $candidate) ?? $candidate;
        $candidate = trim((string) preg_replace('/\s+/', ' ', $candidate));

        if ('' === $candidate) {
            return '';
        }

        if ('formation' === $kind) {
            return 'Formation ' . $candidate;
        }

        return $candidate;
    }

    private function normalizeLooseText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (false !== $ascii) {
                $normalized = strtolower($ascii);
            }
        }

        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function normalizeForCompare(array $details): array
    {
        ksort($details);

        foreach ($details as $key => $value) {
            if (is_string($value)) {
                $details[$key] = trim($value);
            }
        }

        return $details;
    }
}
