<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-autre-ml-demandes',
    description: 'Insere des demandes Autre confirmees pour bootstrapper le retrieval ML.'
)]
class SeedAutreMlDemandesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('employee-id', null, InputOption::VALUE_REQUIRED, 'Employe cible.', '181')
            ->addOption('enterprise-id', null, InputOption::VALUE_REQUIRED, 'Entreprise a associer si --create-employee cree un employe.', null)
            ->addOption('employee-password', null, InputOption::VALUE_REQUIRED, 'Mot de passe temporaire si --create-employee cree un compte.', null)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de seeds a inserer.', '2200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche sans ecrire en base.')
            ->addOption('create-employee', null, InputOption::VALUE_NONE, 'Cree un employe minimal si employee-id est absent.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = $this->em->getConnection();
        $employeeId = max(1, (int) $input->getOption('employee-id'));
        $limit = max(1, min(5000, (int) $input->getOption('limit')));
        $dryRun = (bool) $input->getOption('dry-run');

        $employeeExists = ((int) $connection->fetchOne('SELECT COUNT(*) FROM employe WHERE id_employe = ?', [$employeeId])) > 0;
        $createEmployee = true === ($input->getOption('create-employee') ?? false);
        if (false === $employeeExists) {
            if (false === $createEmployee) {
                $io->error(sprintf('Employe #%d introuvable. Relancez avec --create-employee pour creer un compte seed minimal.', $employeeId));

                return Command::FAILURE;
            }

            $enterpriseId = $this->resolveSeedEnterpriseId($connection, $input->getOption('enterprise-id'));
            if (null === $enterpriseId) {
                $io->error('Aucune entreprise disponible pour creer l employe seed. Creez une entreprise ou passez --enterprise-id.');

                return Command::FAILURE;
            }

            if (false === $dryRun) {
                $connection->insert('employe', [
                    'id_employe' => $employeeId,
                    'nom' => 'Seed',
                    'prenom' => 'ML',
                    'e_mail' => sprintf('seed.ml.%d@example.local', $employeeId),
                    'telephone' => 20000000 + $employeeId,
                    'poste' => 'Seed ML',
                    'role' => 'employe',
                    'date_embauche' => (new \DateTimeImmutable('-2 years'))->format('Y-m-d'),
                    'image_profil' => null,
                    'id_entreprise' => $enterpriseId,
                    'cv_data' => null,
                    'cv_nom' => null,
                ]);

                $plainPassword = trim((string) ($input->getOption('employee-password') ?? ''));
                if ('' === $plainPassword) {
                    $plainPassword = sprintf('Momentum@%d', $employeeId);
                }
                $connection->insert('compte', [
                    'id_employe' => $employeeId,
                    'mot_de_passe' => password_hash($plainPassword, PASSWORD_DEFAULT),
                ]);
            }
            $io->note(sprintf('Employe seed #%d %s.', $employeeId, $dryRun ? 'serait cree' : 'cree'));
        }

        $seeds = array_slice($this->buildSeeds(), 0, $limit);
        $stats = ['created' => 0, 'skipped' => 0];

        foreach ($seeds as $seed) {
            $exists = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM demande WHERE id_employe = ? AND titre = ? AND type_demande = ?',
                [$employeeId, $seed['title'], $seed['type']]
            ) > 0;

            if ($exists) {
                ++$stats['skipped'];
                continue;
            }

            if ($dryRun) {
                ++$stats['created'];
                $io->writeln(sprintf('[dry-run] %s', $seed['title']));
                continue;
            }

            $connection->insert('demande', [
                'id_employe' => $employeeId,
                'categorie' => $seed['category'],
                'titre' => $seed['title'],
                'description' => $seed['description'],
                'priorite' => $seed['priority'],
                'status' => 'Nouvelle',
                'date_creation' => (new \DateTimeImmutable())->format('Y-m-d'),
                'type_demande' => $seed['type'],
            ]);

            $demandeId = (int) $connection->lastInsertId();
            $details = $this->detailsPayload($seed);
            $connection->insert('demande_details', [
                'id_demande' => $demandeId,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $connection->insert('historique_demande', [
                'id_demande' => $demandeId,
                'ancien_statut' => null,
                'nouveau_statut' => 'Nouvelle',
                'date_action' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'acteur' => sprintf('Seed ML - employe #%d', $employeeId),
                'commentaire' => 'Demande creee',
            ]);

            ++$stats['created'];
        }

        $io->success(sprintf('%d seeds inseres, %d deja presents.', $stats['created'], $stats['skipped']));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $seed
     * @return array<string, mixed>
     */
    private function detailsPayload(array $seed): array
    {
        $details = $seed['details'];
        $fieldPlan = ['add' => [], 'remove' => ['ALL'], 'replaceBase' => true];

        foreach ($seed['fields'] as $field) {
            $key = (string) $field['key'];
            $fieldPlan['add'][] = [
                'key' => $key,
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => $field['required'],
                'value' => $details[$key] ?? '',
                'options' => $field['options'] ?? [],
                'source' => 'seed',
            ];
        }

        return array_merge($details, [
            '_ai_feedback_confirmed' => true,
            '_ai_manual_fields' => false,
            '_ai_confirmed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            '_ai_raw_prompt' => $seed['prompt'],
            '_ai_field_plan' => $fieldPlan,
            '_ai_seed_autre_ml' => true,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSeeds(): array
    {
        return $this->dedupeSeeds(array_merge(
            $this->transportSeeds(),
            $this->parkingSeeds(),
            $this->financeSeeds(),
            $this->itAccessSeeds(),
            $this->materialSeeds(),
            $this->maintenanceSeeds(),
            $this->rhSeeds(),
            $this->trainingAndRoomSeeds(),
            $this->generatedDiverseSeeds(2600)
        ));
    }

    private function resolveSeedEnterpriseId(Connection $connection, mixed $requestedEnterpriseId): ?int
    {
        $requested = null !== $requestedEnterpriseId && '' !== trim((string) $requestedEnterpriseId)
            ? max(1, (int) $requestedEnterpriseId)
            : 0;

        if ($requested > 0) {
            $exists = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM entreprise WHERE id_entreprise = ?',
                [$requested]
            ) > 0;

            return $exists ? $requested : null;
        }

        $fallback = $connection->fetchOne(
            'SELECT id_entreprise FROM entreprise ORDER BY CASE WHEN id_entreprise = 102 THEN 0 WHEN id_entreprise = 105 THEN 1 ELSE 2 END, id_entreprise LIMIT 1'
        );

        return false !== $fallback && null !== $fallback ? (int) $fallback : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transportSeeds(): array
    {
        $rows = [
            ['transport de tunis vers rades uniquement en bus', 'Tunis', 'Rades', 'Bus', 'Uniquement en bus'],
            ['trajet depuis ariana vers lac 2 en taxi pour reunion client', 'Ariana', 'Lac 2', 'Taxi', ''],
            ['besoin navette de manouba vers charguia demain matin', 'Manouba', 'Charguia', 'Navette', ''],
            ['deplacement de sfax vers sousse en train le 12 mai', 'Sfax', 'Sousse', 'Train', ''],
            ['voiture service de bardo vers megrine sans taxi svp', 'Bardo', 'Megrine', 'Voiture de service', 'Sans taxi'],
            ['aller de tunis marine vers technopole uniquement train', 'Tunis Marine', 'Technopole', 'Train', 'Uniquement train'],
            ['transport lac 1 vers aeroport en taxi urgent', 'Lac 1', 'Aeroport', 'Taxi', ''],
            ['de hammamet vers nabeul en bus pas de taxi', 'Hammamet', 'Nabeul', 'Bus', 'Pas de taxi'],
            ['depart: centre urbain nord destination: rades en navette', 'Centre Urbain Nord', 'Rades', 'Navette', ''],
            ['besoin transport de tunis vers la marsa uniquement bus', 'Tunis', 'La Marsa', 'Bus', 'Uniquement bus'],
            ['trajet du siege vers client rades en voiture service', 'Siege', 'Rades', 'Voiture de service', ''],
            ['de ben arous vers lac 2 en taxi apres midi', 'Ben Arous', 'Lac 2', 'Taxi', ''],
        ];

        return array_map(fn (array $row): array => $this->seed(
            'Transport ' . $row[1] . ' vers ' . $row[2],
            $row[0],
            'NORMALE',
            [
                'ai_lieu_depart_actuel' => $row[1],
                'ai_lieu_souhaite' => $row[2],
                'ai_type_transport' => $row[3],
            ] + ('' !== $row[4] ? ['ai_contrainte' => $row[4]] : []),
            [
                $this->field('ai_lieu_depart_actuel', 'Lieu de depart', 'text', true),
                $this->field('ai_lieu_souhaite', 'Destination', 'text', true),
                $this->field('ai_type_transport', 'Type de transport', 'select', false, ['Bus', 'Train', 'Taxi', 'Voiture de service', 'Navette']),
                $this->field('ai_contrainte', 'Contrainte', 'text', false),
            ]
        ), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parkingSeeds(): array
    {
        $rows = [
            ['parking pres d un arbre pour entorse', 'Pres d un arbre', 'Place reservee'],
            ['place parking entree principale pendant une semaine', 'Entree principale', 'Place reservee'],
            ['badge parking cote ascenseur niveau -1', 'Cote ascenseur niveau -1', 'Acces parking'],
            ['stationnement temporaire devant batiment B demain', 'Devant batiment B', 'Autorisation temporaire'],
            ['parking proche de la sortie car bequilles', 'Proche de la sortie', 'Place reservee'],
            ['place reservee zone nord pres ascenseur', 'Zone nord pres ascenseur', 'Place reservee'],
            ['acces parking sous sol pour visite fournisseur', 'Sous sol', 'Acces parking'],
            ['parking loin soleil, plutot sous arbre', 'Sous arbre', 'Place reservee'],
            ['stationement pres entree livraison', 'Pres entree livraison', 'Autorisation temporaire'],
            ['badge parking niveau 2 cote gauche', 'Niveau 2 cote gauche', 'Acces parking'],
            ['place parking batiment A proche rampe', 'Batiment A proche rampe', 'Place reservee'],
            ['parking temporaire pour deux jours zone visiteurs', 'Zone visiteurs', 'Autorisation temporaire'],
        ];

        return array_map(fn (array $row): array => $this->seed(
            'Parking ' . $row[1],
            $row[0],
            'NORMALE',
            ['ai_zone_souhaitee' => $row[1], 'ai_type_stationnement' => $row[2]],
            [
                $this->field('ai_zone_souhaitee', 'Zone souhaitee', 'text', true),
                $this->field('ai_type_stationnement', 'Type de stationnement', 'select', true, ['Place reservee', 'Acces parking', 'Autorisation temporaire', 'Autre']),
            ]
        ), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function financeSeeds(): array
    {
        $rows = [
            ['remboursement taxi 85 tnd mission client', 'Transport', '85', 'Mission client'],
            ['note resto 42 dt avec equipe projet', 'Restaurant', '42', 'Equipe projet'],
            ['remboursement hotel 180 tnd nuit mission sfax', 'Hotel', '180', 'Mission Sfax'],
            ['frais internet 55 tnd teletravail', 'Internet', '55', 'Teletravail'],
            ['medicaments 23 dt suite malaise au bureau', 'Medicaments', '23', 'Malaise au bureau'],
            ['taxi 18 dinars pour rentrer apres astreinte', 'Transport', '18', 'Astreinte'],
            ['restaurant 65 tnd repas client', 'Restaurant', '65', 'Repas client'],
            ['hotel 210 tnd formation sousse', 'Hotel', '210', 'Formation Sousse'],
            ['connexion 4g 35 dt pour support urgent', 'Internet', '35', 'Support urgent'],
            ['pharmacie 17 tnd ordonnance medecin', 'Medicaments', '17', 'Ordonnance medecin'],
            ['frais train 14 tnd reunion bizerte', 'Transport', '14', 'Reunion Bizerte'],
            ['dejeuner projet 38 tnd avec fournisseur', 'Restaurant', '38', 'Projet fournisseur'],
        ];

        return array_map(fn (array $row): array => $this->seed(
            'Remboursement ' . $row[1] . ' ' . $row[2] . ' TND',
            $row[0],
            'NORMALE',
            ['ai_type_depense' => $row[1], 'ai_montant' => $row[2], 'ai_justification_metier' => $row[3]],
            [
                $this->field('ai_type_depense', 'Type de depense', 'select', true, ['Transport', 'Restaurant', 'Hotel', 'Internet', 'Medicaments', 'Autre']),
                $this->field('ai_montant', 'Montant', 'number', true),
                $this->field('ai_justification_metier', 'Justification', 'textarea', false),
            ]
        ), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itAccessSeeds(): array
    {
        $rows = [
            ['acces vpn lecture seule pour support nuit', 'VPN', 'Lecture seule', 'Support nuit'],
            ['besoin acces CRM ecriture pour nouveau portefeuille', 'CRM', 'Lecture/Ecriture', 'Nouveau portefeuille'],
            ['jira admin pour gerer sprint equipe mobile', 'Jira', 'Administrateur', 'Sprint equipe mobile'],
            ['acces salesforce lecture pour suivi client', 'Salesforce', 'Lecture seule', 'Suivi client'],
            ['ouvrir compte gitlab ecriture projet data', 'Gitlab', 'Lecture/Ecriture', 'Projet data'],
            ['badge application rh consultation uniquement', 'Badge', 'Lecture seule', 'Consultation RH'],
            ['vpn admin temporaire incident prod', 'VPN', 'Administrateur', 'Incident prod'],
            ['crm lecture ecriture remplacement collegue', 'CRM', 'Lecture/Ecriture', 'Remplacement collegue'],
            ['jira consultation pour recette bug', 'Jira', 'Lecture seule', 'Recette bug'],
            ['sap acces admin cloture mois', 'SAP', 'Administrateur', 'Cloture mois'],
            ['github ecriture repo frontend', 'Github', 'Lecture/Ecriture', 'Repo frontend'],
            ['acces vpn pour teletravail demain', 'VPN', 'Lecture/Ecriture', 'Teletravail demain'],
        ];

        return array_map(fn (array $row): array => $this->seed(
            'Acces ' . $row[1],
            $row[0],
            'NORMALE',
            ['ai_systeme_concerne' => $row[1], 'ai_type_acces' => $row[2], 'ai_justification_metier' => $row[3]],
            [
                $this->field('ai_systeme_concerne', 'Systeme concerne', 'text', true),
                $this->field('ai_type_acces', 'Type d acces', 'select', false, ['Lecture seule', 'Lecture/Ecriture', 'Administrateur', 'Autre']),
                $this->field('ai_justification_metier', 'Justification', 'textarea', false),
            ]
        ), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function materialSeeds(): array
    {
        $rows = [
            ['clavier ergonomique pour tendinite', 'clavier', 'ergonomique', 'Tendinite'],
            ['souris verticale sans fil douleur poignet', 'souris', 'verticale sans fil', 'Douleur poignet'],
            ['ecran 32 pouces pour analyse dashboard', 'ecran', '32 pouces', 'Analyse dashboard'],
            ['casque antibruit pour open space', 'casque antibruit', 'antibruit', 'Open space'],
            ['docking station usb c pour laptop', 'docking station', 'usb c', 'Laptop'],
            ['webcam hd pour reunion client', 'webcam', 'hd', 'Reunion client'],
            ['ordinateur portable 16go ram dev react', 'ordinateur portable', '16go ram', 'Dev React'],
            ['imprimante badge bureau accueil', 'imprimante', 'badge', 'Bureau accueil'],
            ['micro casque gaming pour visio longue', 'micro casque', 'gaming', 'Visio longue'],
            ['ecran incurve 27 pouces finance', 'ecran', '27 pouces incurve', 'Finance'],
            ['clavier mecanique azerty', 'clavier', 'mecanique azerty', ''],
            ['souris bluetooth compacte deplacement', 'souris', 'bluetooth compacte', 'Deplacement'],
        ];

        return array_map(fn (array $row): array => $this->seed(
            'Materiel ' . $row[1] . ' ' . $row[2],
            $row[0],
            'NORMALE',
            array_filter(['ai_materiel_concerne' => $row[1], 'ai_specification' => $row[2], 'ai_justification_metier' => $row[3]], static fn ($value): bool => '' !== $value),
            [
                $this->field('ai_materiel_concerne', 'Materiel concerne', 'text', true),
                $this->field('ai_specification', 'Specification', 'text', false),
                $this->field('ai_justification_metier', 'Justification', 'textarea', false),
            ]
        ), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function maintenanceSeeds(): array
    {
        $rows = [
            ['nettoyage urgent cafe renverse sur moquette', 'Nettoyage', 'Cafe renverse', 'Moquette'],
            ['clim bureau 3 en panne bruit fort', 'Climatisation', 'Panne clim', 'Bureau 3'],
            ['serrure salle archive bloquee', 'Serrure', 'Serrure bloquee', 'Salle archive'],
            ['maintenance machine a cafe fuite eau', 'Maintenance', 'Fuite eau', 'Machine a cafe'],
            ['nettoyage open space apres repas equipe', 'Nettoyage', 'Repas renverse', 'Open space'],
            ['reparation porte entree qui ferme mal', 'Serrure', 'Porte ferme mal', 'Entree'],
            ['climatisation salle serveur trop chaude', 'Climatisation', 'Temperature haute', 'Salle serveur'],
            ['nettoyage vitre bureau direction', 'Nettoyage', 'Vitre sale', 'Bureau direction'],
            ['panne luminaire couloir etage 2', 'Maintenance', 'Luminaire panne', 'Couloir etage 2'],
            ['serrure casier personnel casse', 'Serrure', 'Casier casse', 'Casier personnel'],
            ['cafe renverser sur tapis accueil', 'Nettoyage', 'Cafe renverse', 'Tapis accueil'],
            ['maintenance clim open space souffle chaud', 'Climatisation', 'Souffle chaud', 'Open space'],
        ];

        return array_map(fn (array $row): array => $this->seed(
            $row[1] . ' - ' . $row[3],
            $row[0],
            'HAUTE',
            ['ai_type_intervention' => $row[1], 'ai_nature_incident' => $row[2], 'ai_equipement_concerne' => $row[3]],
            [
                $this->field('ai_type_intervention', 'Type intervention', 'text', true),
                $this->field('ai_nature_incident', 'Nature incident', 'text', false),
                $this->field('ai_equipement_concerne', 'Equipement concerne', 'text', false),
            ]
        ), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rhSeeds(): array
    {
        $rows = [
            ['attestation de salaire pour banque', ['ai_type_attestation' => 'attestation de salaire', 'ai_organisme_destinataire' => 'Banque']],
            ['attestation de travail pour visa', ['ai_type_attestation' => 'attestation de travail', 'ai_motif_contexte' => 'Visa']],
            ['conge annuel du 10 au 15 juin', ['ai_type_conge' => 'Conge annuel', 'ai_motif_conge' => 'Repos annuel']],
            ['conge maladie 2 jours certificat medical', ['ai_type_conge' => 'Conge maladie', 'ai_motif_conge' => 'Certificat medical']],
            ['changement horaire de 8h a 16h pour transport', ['ai_horaire_actuel' => '8h', 'ai_horaire_souhaite' => '16h', 'ai_motif_changement' => 'Transport']],
            ['shift nuit temporaire cette semaine', ['ai_horaire_souhaite' => 'Nuit', 'ai_motif_changement' => 'Cette semaine']],
            ['attestation salaire dossier credit', ['ai_type_attestation' => 'attestation de salaire', 'ai_motif_contexte' => 'Dossier credit']],
            ['attestation travail pour inscription ecole', ['ai_type_attestation' => 'attestation de travail', 'ai_organisme_destinataire' => 'Ecole']],
            ['conge sans solde mois aout', ['ai_type_conge' => 'Conge sans solde', 'ai_motif_conge' => 'Mois aout']],
            ['absence maladie demain matin', ['ai_type_conge' => 'Conge maladie', 'ai_motif_conge' => 'Maladie']],
            ['passer shift soir pour garde enfant', ['ai_horaire_souhaite' => 'Soir', 'ai_motif_changement' => 'Garde enfant']],
            ['changer horaire 9h-17h au lieu de 8h-16h', ['ai_horaire_actuel' => '8h-16h', 'ai_horaire_souhaite' => '9h-17h']],
        ];

        return array_map(function (array $row): array {
            $fields = [];
            foreach (array_keys($row[1]) as $key) {
                $fields[] = $this->field($key, ucfirst(str_replace('_', ' ', preg_replace('/^ai_/', '', $key) ?? $key)), str_contains($key, 'conge') ? 'select' : 'text', true);
            }

            return $this->seed('RH ' . ucfirst($row[0]), $row[0], 'NORMALE', $row[1], $fields);
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trainingAndRoomSeeds(): array
    {
        $rows = [
            ['formation professionnelle en UI/UX', ['ai_nom_formation' => 'UI/UX', 'ai_type_formation' => 'Formation externe']],
            ['formation avancee en react pour front', ['ai_nom_formation' => 'React', 'ai_type_formation' => 'Formation externe']],
            ['certification scrum master', ['ai_nom_formation' => 'Scrum master', 'ai_type_formation' => 'Certification']],
            ['atelier excel avance finance', ['ai_nom_formation' => 'Excel avance', 'ai_type_formation' => 'Formation interne']],
            ['reservation salle focus pour atelier produit', ['ai_salle' => 'Focus', 'ai_motif' => 'Atelier produit']],
            ['salle innovation a 16h pour 45 minutes', ['ai_salle' => 'Innovation', 'ai_horaire_souhaite' => '16h', 'ai_duree' => '45 minutes']],
            ['formation devops k8s externe', ['ai_nom_formation' => 'Devops k8s', 'ai_type_formation' => 'Formation externe']],
            ['cours anglais business equipe vente', ['ai_nom_formation' => 'Anglais business', 'ai_type_formation' => 'Formation interne']],
            ['certification aws cloud practitioner', ['ai_nom_formation' => 'AWS cloud practitioner', 'ai_type_formation' => 'Certification']],
            ['reserver salle atlas demain 10h', ['ai_salle' => 'Atlas', 'ai_horaire_souhaite' => '10h']],
            ['meeting room carthage pour client', ['ai_salle' => 'Carthage', 'ai_motif' => 'Client']],
            ['formation securite information sans certification', ['ai_nom_formation' => 'Securite information', 'ai_type_formation' => 'Formation interne']],
        ];

        return array_map(function (array $row): array {
            $fields = [];
            foreach (array_keys($row[1]) as $key) {
                $fields[] = $this->field($key, ucfirst(str_replace('_', ' ', preg_replace('/^ai_/', '', $key) ?? $key)), str_contains($key, 'type') ? 'select' : 'text', true, ['Formation interne', 'Formation externe', 'Certification']);
            }

            return $this->seed('Seed ' . ucfirst($row[0]), $row[0], 'NORMALE', $row[1], $fields);
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generatedDiverseSeeds(int $target): array
    {
        $origins = ['Tunis', 'Ariana', 'Bardo', 'Lac 1', 'Lac 2', 'Ben Arous', 'Manouba', 'Sfax', 'Sousse', 'Nabeul', 'Hammamet', 'Bizerte', 'Megrine', 'Charguia', 'Centre Urbain Nord'];
        $destinations = ['Rades', 'La Marsa', 'Aeroport', 'Technopole', 'Sousse', 'Sfax', 'Bizerte', 'Nabeul', 'Lac 2', 'Charguia', 'Megrine', 'Menzah', 'Mourouj', 'Hammam Lif', 'Siege'];
        $transportModes = ['Bus', 'Train', 'Taxi', 'Voiture de service', 'Navette'];
        $constraints = ['', 'uniquement en bus', 'sans taxi', 'avec retour le soir', 'pas de retard', 'depart tot'];

        $materials = ['clavier', 'souris', 'ecran', 'casque antibruit', 'docking station', 'webcam', 'ordinateur portable', 'imprimante', 'micro casque', 'station accueil', 'chaise ergonomique', 'tablette graphique'];
        $specs = ['ergonomique', 'verticale sans fil', '32 pouces', '27 pouces', 'usb c', 'bluetooth', 'hd', '16go ram', 'azerty', 'compacte', 'antibruit', 'hauteur reglable'];
        $materialReasons = ['tendinite', 'douleur poignet', 'open space', 'analyse dashboard', 'visio client', 'teletravail', 'dev frontend', 'support hotline', 'design produit', 'mobilite terrain'];

        $systems = ['VPN', 'CRM', 'Jira', 'Salesforce', 'SAP', 'Gitlab', 'Github', 'Badge', 'ERP', 'Sharepoint', 'Confluence', 'Power BI'];
        $accessTypes = ['Lecture seule', 'Lecture/Ecriture', 'Administrateur'];
        $accessReasons = ['nouveau poste', 'support nuit', 'recette bug', 'cloture mois', 'projet client', 'remplacement collegue', 'teletravail', 'audit interne'];

        $expenseTypes = ['Transport', 'Restaurant', 'Hotel', 'Internet', 'Medicaments'];
        $amounts = ['18', '35', '42', '55', '65', '80', '85', '120', '180', '210', '260', '320'];
        $expenseReasons = ['mission client', 'formation externe', 'astreinte', 'deplacement terrain', 'reunion fournisseur', 'support urgent', 'teletravail', 'visite site'];

        $parkingZones = ['pres entree principale', 'cote ascenseur niveau -1', 'zone visiteurs', 'sous arbre', 'devant batiment B', 'proche rampe', 'niveau 2 gauche', 'sous sol', 'pres sortie', 'bloc A'];
        $parkingTypes = ['Place reservee', 'Acces parking', 'Autorisation temporaire'];

        $attestations = ['attestation de salaire', 'attestation de travail', 'certificat de travail'];
        $attestationReasons = ['banque', 'visa', 'dossier credit', 'inscription ecole', 'location appartement', 'administration'];

        $leaveTypes = ['Conge annuel', 'Conge maladie', 'Conge sans solde'];
        $leaveReasons = ['repos annuel', 'maladie', 'rendez vous medical', 'affaire familiale', 'voyage personnel', 'certificat medical'];

        $trainingNames = ['React', 'UI UX', 'Scrum master', 'Excel avance', 'DevOps k8s', 'Anglais business', 'AWS cloud practitioner', 'Cybersecurite', 'SQL reporting', 'Leadership'];
        $trainingTypes = ['Formation interne', 'Formation externe', 'Certification'];

        $rooms = ['Focus', 'Innovation', 'Atlas', 'Carthage', 'Jasmin', 'Cobalt', 'Nexus', 'Agora'];
        $meetingReasons = ['atelier produit', 'reunion client', 'retro sprint', 'formation equipe', 'comite projet', 'entretien fournisseur'];

        $interventions = [
            ['Nettoyage', 'Cafe renverse', 'Moquette'],
            ['Climatisation', 'Panne clim', 'Open space'],
            ['Serrure', 'Serrure bloquee', 'Salle archive'],
            ['Maintenance', 'Fuite eau', 'Machine a cafe'],
            ['Maintenance', 'Luminaire panne', 'Couloir etage 2'],
            ['Nettoyage', 'Tapis sale', 'Accueil'],
        ];

        $shifts = [
            ['Jour', '8h', '17h', 'Semaine en cours'],
            ['Nuit', '22h', '6h', 'Semaine prochaine'],
            ['Soir', '14h', '22h', 'Mois en cours'],
            ['Matin', '7h', '15h', 'Deux semaines'],
        ];

        $seeds = [];
        for ($index = 0; count($seeds) < $target; ++$index) {
            $bucket = $index % 12;
            $serial = $index + 1;

            switch ($bucket) {
                case 0:
                    $from = $origins[$index % count($origins)];
                    $to = $destinations[(int) floor($index / count($origins)) % count($destinations)];
                    if ($from === $to) {
                        $to = $destinations[($index + 3) % count($destinations)];
                    }
                    $mode = $transportModes[$index % count($transportModes)];
                    $constraint = $constraints[$index % count($constraints)];
                    $prompt = trim(sprintf('transport de %s vers %s en %s %s', strtolower($from), strtolower($to), strtolower($mode), $constraint));
                    $details = [
                        'ai_lieu_depart_actuel' => $from,
                        'ai_lieu_souhaite' => $to,
                        'ai_type_transport' => $mode,
                    ];
                    if ('' !== $constraint) {
                        $details['ai_contrainte'] = ucfirst($constraint);
                    }
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d Transport %s vers %s', $serial, $from, $to),
                        $prompt,
                        'NORMALE',
                        $details,
                        [
                            $this->field('ai_lieu_depart_actuel', 'Lieu de depart', 'text', true),
                            $this->field('ai_lieu_souhaite', 'Destination', 'text', true),
                            $this->field('ai_type_transport', 'Type de transport', 'select', false, $transportModes),
                            $this->field('ai_contrainte', 'Contrainte', 'text', false),
                        ],
                        'Administrative',
                        'Autre'
                    );
                    break;

                case 1:
                    $material = $materials[$index % count($materials)];
                    $spec = $specs[(int) floor($index / count($materials)) % count($specs)];
                    $reason = $materialReasons[$index % count($materialReasons)];
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d Materiel %s %s', $serial, $material, $spec),
                        sprintf('besoin de %s %s pour %s', $material, $spec, $reason),
                        'NORMALE',
                        [
                            'ai_materiel_concerne' => $material,
                            'ai_specification' => $spec,
                            'ai_justification_metier' => ucfirst($reason),
                        ],
                        [
                            $this->field('ai_materiel_concerne', 'Materiel concerne', 'text', true),
                            $this->field('ai_specification', 'Specification', 'text', false),
                            $this->field('ai_justification_metier', 'Justification', 'textarea', false),
                        ],
                        'Informatique',
                        'Materiel informatique'
                    );
                    break;

                case 2:
                    $system = $systems[$index % count($systems)];
                    $access = $accessTypes[(int) floor($index / count($systems)) % count($accessTypes)];
                    $reason = $accessReasons[$index % count($accessReasons)];
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d Acces %s %s', $serial, $system, $access),
                        sprintf('acces %s %s pour %s', strtolower($system), strtolower($access), $reason),
                        'NORMALE',
                        [
                            'ai_systeme_concerne' => $system,
                            'ai_type_acces' => $access,
                            'ai_justification_metier' => ucfirst($reason),
                        ],
                        [
                            $this->field('ai_systeme_concerne', 'Systeme concerne', 'text', true),
                            $this->field('ai_type_acces', 'Type d acces', 'select', false, ['Lecture seule', 'Lecture/Ecriture', 'Administrateur', 'Autre']),
                            $this->field('ai_justification_metier', 'Justification', 'textarea', false),
                        ],
                        'Informatique',
                        'Acces systeme'
                    );
                    break;

                case 3:
                    $expense = $expenseTypes[$index % count($expenseTypes)];
                    $amount = $amounts[(int) floor($index / count($expenseTypes)) % count($amounts)];
                    $reason = $expenseReasons[$index % count($expenseReasons)];
                    $prompt = sprintf('remboursement %s %s tnd pour %s', strtolower($expense), $amount, $reason);
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d Remboursement %s %s TND', $serial, $expense, $amount),
                        $prompt,
                        'NORMALE',
                        [
                            'ai_type_depense' => $expense,
                            'ai_montant' => $amount,
                            'ai_justification_metier' => ucfirst($reason),
                        ],
                        [
                            $this->field('ai_type_depense', 'Type de depense', 'select', true, ['Transport', 'Restaurant', 'Hotel', 'Internet', 'Medicaments', 'Autre']),
                            $this->field('ai_montant', 'Montant', 'number', true),
                            $this->field('ai_justification_metier', 'Justification', 'textarea', false),
                        ],
                        'Administrative',
                        'Remboursement'
                    );
                    break;

                case 4:
                    $zone = $parkingZones[$index % count($parkingZones)];
                    $parkingType = $parkingTypes[(int) floor($index / count($parkingZones)) % count($parkingTypes)];
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d Parking %s', $serial, $zone),
                        sprintf('parking %s %s', $zone, strtolower($parkingType)),
                        'NORMALE',
                        [
                            'ai_zone_souhaitee' => ucfirst($zone),
                            'ai_type_stationnement' => $parkingType,
                        ],
                        [
                            $this->field('ai_zone_souhaitee', 'Zone souhaitee', 'text', true),
                            $this->field('ai_type_stationnement', 'Type de stationnement', 'select', true, ['Place reservee', 'Acces parking', 'Autorisation temporaire', 'Autre']),
                        ],
                        'Administrative',
                        'Autre'
                    );
                    break;

                case 5:
                    $attestation = $attestations[$index % count($attestations)];
                    $reason = $attestationReasons[(int) floor($index / count($attestations)) % count($attestationReasons)];
                    $details = ['ai_type_attestation' => $attestation];
                    if (in_array($reason, ['banque', 'inscription ecole'], true)) {
                        $details['ai_organisme_destinataire'] = ucfirst(str_replace('inscription ', '', $reason));
                    } else {
                        $details['ai_motif_contexte'] = ucfirst($reason);
                    }
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d %s %s', $serial, ucfirst($attestation), $reason),
                        sprintf('%s pour %s', $attestation, $reason),
                        'NORMALE',
                        $details,
                        [
                            $this->field('ai_type_attestation', 'Type d attestation', 'text', true),
                            $this->field('ai_organisme_destinataire', 'Organisme destinataire', 'text', false),
                            $this->field('ai_motif_contexte', 'Motif contexte', 'text', false),
                        ],
                        'Ressources Humaines',
                        str_contains($attestation, 'salaire') ? 'Attestation de salaire' : 'Attestation de travail'
                    );
                    break;

                case 6:
                    $leave = $leaveTypes[$index % count($leaveTypes)];
                    $reason = $leaveReasons[(int) floor($index / count($leaveTypes)) % count($leaveReasons)];
                    $day = 1 + ($index % 25);
                    $endDay = min(28, $day + 2);
                    $prompt = sprintf('%s du %d/%02d au %d/%02d pour %s', strtolower($leave), $day, 6 + ($index % 4), $endDay, 6 + ($index % 4), $reason);
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d %s %s', $serial, $leave, $reason),
                        $prompt,
                        'NORMALE',
                        [
                            'ai_type_conge' => $leave,
                            'ai_motif_conge' => ucfirst($reason),
                        ],
                        [
                            $this->field('ai_type_conge', 'Type de conge', 'select', true, ['Conge annuel', 'Conge maladie', 'Conge sans solde', 'Autre']),
                            $this->field('ai_date_debut_conge', 'Date debut conge', 'date', true),
                            $this->field('ai_date_fin_conge', 'Date fin conge', 'date', true),
                            $this->field('ai_motif_conge', 'Motif', 'textarea', false),
                        ],
                        'Ressources Humaines',
                        'Conge'
                    );
                    break;

                case 7:
                    $training = $trainingNames[$index % count($trainingNames)];
                    $trainingType = $trainingTypes[(int) floor($index / count($trainingNames)) % count($trainingTypes)];
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d %s %s', $serial, $trainingType, $training),
                        sprintf('%s en %s pour equipe %s', strtolower($trainingType), strtolower($training), strtolower($systems[$index % count($systems)])),
                        'NORMALE',
                        [
                            'ai_nom_formation' => $training,
                            'ai_type_formation' => $trainingType,
                        ],
                        [
                            $this->field('ai_nom_formation', 'Nom de la formation', 'text', true),
                            $this->field('ai_type_formation', 'Type de formation', 'select', false, ['Formation interne', 'Formation externe', 'Certification']),
                        ],
                        'Formation',
                        $trainingType
                    );
                    break;

                case 8:
                    $room = $rooms[$index % count($rooms)];
                    $reason = $meetingReasons[(int) floor($index / count($rooms)) % count($meetingReasons)];
                    $hour = 8 + ($index % 10);
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d Salle %s', $serial, $room),
                        sprintf('reservation salle %s a %dh pour %s', strtolower($room), $hour, $reason),
                        'NORMALE',
                        [
                            'ai_salle' => $room,
                            'ai_horaire_souhaite' => sprintf('%dh', $hour),
                            'ai_motif' => ucfirst($reason),
                        ],
                        [
                            $this->field('ai_salle', 'Salle', 'text', true),
                            $this->field('ai_horaire_souhaite', 'Horaire souhaite', 'text', false),
                            $this->field('ai_motif', 'Motif', 'textarea', false),
                        ],
                        'Organisation du travail',
                        'Autre'
                    );
                    break;

                case 9:
                    $intervention = $interventions[$index % count($interventions)];
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d %s %s', $serial, $intervention[0], $intervention[2]),
                        sprintf('%s %s sur %s', strtolower($intervention[0]), strtolower($intervention[1]), strtolower($intervention[2])),
                        'HAUTE',
                        [
                            'ai_type_intervention' => $intervention[0],
                            'ai_nature_incident' => $intervention[1],
                            'ai_equipement_concerne' => $intervention[2],
                        ],
                        [
                            $this->field('ai_type_intervention', 'Type intervention', 'text', true),
                            $this->field('ai_nature_incident', 'Nature incident', 'text', false),
                            $this->field('ai_equipement_concerne', 'Equipement concerne', 'text', false),
                        ],
                        'Informatique',
                        'Probleme technique'
                    );
                    break;

                case 10:
                    $shift = $shifts[$index % count($shifts)];
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d Shift %s', $serial, $shift[0]),
                        sprintf('changement horaire shift %s de %s a %s pendant %s', strtolower($shift[0]), $shift[1], $shift[2], strtolower($shift[3])),
                        'NORMALE',
                        [
                            'ai_type_demande' => 'Shift de ' . strtolower($shift[0]),
                            'ai_shift_souhaite' => $shift[0],
                            'ai_horaire_souhaite' => $shift[1] . '-' . $shift[2],
                            'ai_periode_concernee' => $shift[3],
                        ],
                        [
                            $this->field('ai_type_demande', 'Type de demande', 'text', true),
                            $this->field('ai_shift_souhaite', 'Shift souhaite', 'text', true),
                            $this->field('ai_horaire_souhaite', 'Horaire souhaite', 'text', true),
                            $this->field('ai_periode_concernee', 'Periode concernee', 'text', true),
                        ],
                        'Organisation du travail',
                        'Changement horaires'
                    );
                    break;

                default:
                    $amount = $amounts[$index % count($amounts)];
                    $reason = $expenseReasons[(int) floor($index / count($amounts)) % count($expenseReasons)];
                    $seeds[] = $this->seed(
                        sprintf('AUTO-%04d Avance salaire %s TND', $serial, $amount),
                        sprintf('avance sur salaire %s tnd pour %s', $amount, $reason),
                        'NORMALE',
                        [
                            'ai_montant' => $amount,
                            'ai_justification_metier' => ucfirst($reason),
                        ],
                        [
                            $this->field('ai_montant', 'Montant', 'number', true),
                            $this->field('ai_justification_metier', 'Justification', 'textarea', true),
                        ],
                        'Administrative',
                        'Avance sur salaire'
                    );
                    break;
            }
        }

        return $seeds;
    }

    /**
     * @param array<int, array<string, mixed>> $seeds
     * @return array<int, array<string, mixed>>
     */
    private function dedupeSeeds(array $seeds): array
    {
        $deduped = [];
        $seen = [];
        foreach ($seeds as $seed) {
            $signature = strtolower(trim((string) ($seed['category'] ?? '')) . '|' . trim((string) ($seed['type'] ?? '')) . '|' . trim((string) ($seed['title'] ?? '')));
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $deduped[] = $seed;
        }

        return $deduped;
    }

    /**
     * @param array<string, string> $details
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function seed(
        string $title,
        string $prompt,
        string $priority,
        array $details,
        array $fields,
        string $category = 'Autre',
        string $type = 'Autre'
    ): array
    {
        return [
            'title' => $this->cleanSeedTitle($title),
            'description' => ucfirst($prompt) . '.',
            'prompt' => $prompt,
            'priority' => $priority,
            'category' => $category,
            'type' => $type,
            'details' => $details,
            'fields' => $fields,
        ];
    }

    private function cleanSeedTitle(string $title): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $title));
        $clean = preg_replace('/^AUTO-\d+\s+/i', '', $clean) ?? $clean;
        $clean = preg_replace('/^Seed\s+/i', '', $clean) ?? $clean;
        $clean = preg_replace('/^RH\s+/i', '', $clean) ?? $clean;
        $clean = trim($clean);

        return '' !== $clean ? $clean : trim($title);
    }

    /**
     * @param array<int, string> $options
     * @return array<string, mixed>
     */
    private function field(string $key, string $label, string $type, bool $required, array $options = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'options' => $options,
        ];
    }
}
