<?php

namespace App\Services;

use App\Entity\Compte;
use App\Entity\Employe;
use App\Entity\Entreprise;
use App\Repository\EmployeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class EmployeCsvImportService
{
    /**
     * @return array{imported:int, errors:string[], fatalError:?string}
     */
    public function import(UploadedFile $file,Entreprise $entreprise,EmployeRepository $employeRepository,EntityManagerInterface $entityManager,PasswordGenerator $passwordGenerator,MailerInterface $mailer,MailerService $mailerService,UserPasswordHasherInterface $passwordHasher): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension !== 'csv') {
            return [
                'imported' => 0,
                'errors' => [],
                'fatalError' => 'Format invalide. Merci d\'utiliser un fichier .csv.',
            ];
        }

        $existingEmails = [];
        foreach ($employeRepository->findBy(['entreprise' => $entreprise]) as $existingEmploye) {
            $email = strtolower(trim((string) $existingEmploye->getEmail()));
            if ($email !== '') {
                $existingEmails[$email] = true;
            }
        }

        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return [
                'imported' => 0,
                'errors' => [],
                'fatalError' => 'Impossible de lire le fichier CSV.',
            ];
        }

        $delimiter = $this->detectDelimiter($handle);
        rewind($handle);

        $imported = 0;
        $lineNumber = 0;
        $errors = [];
        $pendingEmails = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;

            if ($row === [null] || count(array_filter($row, static fn($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            if ($lineNumber === 1 && $this->isCsvHeader($row)) {
                continue;
            }

            $row = array_map(static fn($value) => trim((string) $value), $row);
            if (isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
            }
            $row = array_pad($row, 7, '');

            [$nom, $prenom, $email, $telephone, $poste, $role, $dateEmbaucheRaw] = $row;

            if ($nom === '' || $prenom === '' || $email === '' || $telephone === '' || $poste === '' || $role === '') {
                $errors[] = sprintf('Ligne %d ignorée: champs obligatoires manquants.', $lineNumber);
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = sprintf('Ligne %d ignorée: email invalide (%s).', $lineNumber, $email);
                continue;
            }

            if (!ctype_digit($telephone)) {
                $errors[] = sprintf('Ligne %d ignorée: téléphone invalide (%s).', $lineNumber, $telephone);
                continue;
            }

            $normalizedRole = $this->normalizeRole($role);
            if ($normalizedRole === null) {
                $errors[] = sprintf('Ligne %d ignorée: rôle invalide (%s).', $lineNumber, $role);
                continue;
            }

            $normalizedEmail = strtolower($email);
            if (isset($existingEmails[$normalizedEmail])) {
                $errors[] = sprintf('Ligne %d ignorée: email déjà existant (%s).', $lineNumber, $email);
                continue;
            }

            $dateEmbauche = null;
            if ($dateEmbaucheRaw !== '') {
                $dateEmbauche = $this->parseCsvDate($dateEmbaucheRaw);
                if ($dateEmbauche === null) {
                    $errors[] = sprintf('Ligne %d ignorée: date embauche invalide (%s).', $lineNumber, $dateEmbaucheRaw);
                    continue;
                }
            }

            $employe = new Employe();
            $employe
                ->setNom($nom)
                ->setPrenom($prenom)
                ->setEmail($email)
                ->setTelephone((int) $telephone)
                ->setPoste($poste)
                ->setRole($normalizedRole)
                ->setDateEmbauche($dateEmbauche)
                ->setEntreprise($entreprise);

            $plainPassword = $passwordGenerator->generatePlain();
            $compte = new Compte();
            $compte->setMot_de_passe($passwordGenerator->hash($plainPassword));
            $compte->setEmploye($employe);

            $entityManager->persist($employe);
            $entityManager->persist($compte);

            $pendingEmails[] = [
                'email' => $email,
                'prenom' => $prenom,
                'nom' => $nom,
                'password' => $plainPassword,
            ];

            $existingEmails[$normalizedEmail] = true;
            $imported++;
        }

        fclose($handle);

        if ($imported > 0) {
            $entityManager->flush();

            foreach ($pendingEmails as $pendingEmail) {
                try {
                    $mailerService->sendTemporaryPassword(
                        $mailer,
                        (string) $pendingEmail['email'],
                        (string) $pendingEmail['prenom'],
                        (string) $pendingEmail['nom'],
                        (string) $pendingEmail['password']
                    );
                } catch (\Throwable $exception) {
                    $errors[] = sprintf('Envoi e-mail échoué pour %s.', (string) $pendingEmail['email']);
                }
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
            'fatalError' => null,
        ];
    }

    private function isCsvHeader(array $row): bool
    {
        $header = strtolower(implode(',', array_map(static fn($value) => trim((string) $value), $row)));

        return str_contains($header, 'nom')
            && str_contains($header, 'prenom')
            && str_contains($header, 'email');
    }

    private function normalizeRole(string $role): ?string
    {
        $normalized = strtolower(trim($role));

        return match ($normalized) {
            'rh' => 'RH',
            'employe', 'employé' => 'employé',
            'chef projet', 'chef de projet' => 'chef projet',
            default => null,
        };
    }

    private function parseCsvDate(string $date): ?\DateTime
    {
        $value = trim($date);
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y'];

        foreach ($formats as $format) {
            $parsedDate = \DateTime::createFromFormat($format, $value);
            if ($parsedDate !== false) {
                return $parsedDate;
            }
        }

        return null;
    }

    private function detectDelimiter($handle): string
    {
        $line = '';
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                break;
            }
        }

        if ($line === false || trim($line) === '') {
            return ',';
        }

        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        $separators = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($separators);
        $best = array_key_first($separators);

        return ($best !== null && $separators[$best] > 0) ? $best : ',';
    }
}
