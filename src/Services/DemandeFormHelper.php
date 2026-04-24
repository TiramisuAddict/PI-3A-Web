<?php

namespace App\Services;

class DemandeFormHelper
{
    private const CATEGORY_TYPES = [
        'Ressources Humaines' => [
            'Conge',
            'Attestation de travail',
            'Attestation de salaire',
            'Certificat de travail',
            'Mutation',
            'Demission',
            'Autre'
        ],
        'Administrative' => [
            'Avance sur salaire',
            'Remboursement',
            'Materiel de bureau',
            'Badge acces',
            'Carte de visite',
            'Autre'
        ],
        'Informatique' => [
            'Materiel informatique',
            'Acces systeme',
            'Logiciel',
            'Probleme technique',
            'Autre'
        ],
        'Formation' => [
            'Formation interne',
            'Formation externe',
            'Certification',
            'Autre'
        ],
        'Organisation du travail' => [
            'Teletravail',
            'Changement horaires',
            'Heures supplementaires',
            'Autre'
        ],
        'Autre' => [
            'Autre'
        ]
    ];

    private const PRIORITES = [
        'HAUTE',
        'NORMALE',
        'BASSE'
    ];

    private const STATUSES = [
        'Nouvelle',
        'En cours',
        'En attente',
        'Resolue',
        'Rejetee',
        'Reconsideration',
        'Fermee',
        'Annulee'
    ];

    private const CATEGORY_ALIASES = [
        'financiere' => 'Administrative',
        'financière' => 'Administrative',
        'finance' => 'Administrative',
        'technique' => 'Informatique',
        'it' => 'Informatique',
        'rh' => 'Ressources Humaines',
        'ressources humaines' => 'Ressources Humaines',
        'organisation travail' => 'Organisation du travail',
    ];

    private const TYPE_ALIASES = [
        'badge d acces' => 'Badge acces',
        'badge d\'accès' => 'Badge acces',
        'badge acces' => 'Badge acces',
        'conge' => 'Conge',
        'congé' => 'Conge',
        'conges' => 'Conge',
        'teletravail' => 'Teletravail',
        'télétravail' => 'Teletravail',
        'heures supplementaires' => 'Heures supplementaires',
        'heures supplémentaires' => 'Heures supplementaires',
        'materiel informatique' => 'Materiel informatique',
        'matériel informatique' => 'Materiel informatique',
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public function getCategoryTypes(): array
    {
        return self::CATEGORY_TYPES;
    }

    /**
     * @return array<int, string>
     */
    public function getCategories(): array
    {
        return array_keys(self::CATEGORY_TYPES);
    }

    /**
     * @return array<int, string>
     */
    public function getTypesForCategory(string $category): array
    {
        return self::CATEGORY_TYPES[$category] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function getPriorites(): array
    {
        return self::PRIORITES;
    }

    /**
     * @return array<int, string>
     */
    public function getStatuses(): array
    {
        return self::STATUSES;
    }

    public function resolveCanonicalCategory(?string $category, ?string $typeDemande = null): ?string
    {
        $rawCategory = trim($category ?? '');
        $rawType = trim($typeDemande ?? '');

        if (isset(self::CATEGORY_TYPES[$rawCategory])) {
            return $rawCategory;
        }

        $normalizedCategory = $this->normalizeTypeKey($rawCategory);
        if ('' !== $normalizedCategory && isset(self::CATEGORY_ALIASES[$normalizedCategory])) {
            return self::CATEGORY_ALIASES[$normalizedCategory];
        }

        foreach (array_keys(self::CATEGORY_TYPES) as $knownCategory) {
            if ($this->normalizeTypeKey($knownCategory) === $normalizedCategory) {
                return $knownCategory;
            }
        }

        if ('' !== $rawType) {
            $owner = $this->findOwningCategoryForType($rawType);
            if (null !== $owner) {
                return $owner;
            }
        }

        return '' !== $rawCategory ? $rawCategory : null;
    }

    public function resolveCanonicalType(?string $typeDemande, ?string $category = null): ?string
    {
        $rawType = trim($typeDemande ?? '');
        if ('' === $rawType) {
            return null;
        }

        $normalizedType = $this->normalizeTypeKey($rawType);
        if (isset(self::TYPE_ALIASES[$normalizedType])) {
            return self::TYPE_ALIASES[$normalizedType];
        }

        $resolvedCategory = $this->resolveCanonicalCategory($category, $rawType);
        if (null !== $resolvedCategory && isset(self::CATEGORY_TYPES[$resolvedCategory])) {
            foreach (self::CATEGORY_TYPES[$resolvedCategory] as $knownType) {
                if ($this->normalizeTypeKey($knownType) === $normalizedType) {
                    return $knownType;
                }
            }
        }

        foreach (self::CATEGORY_TYPES as $types) {
            foreach ($types as $knownType) {
                if ($this->normalizeTypeKey($knownType) === $normalizedType) {
                    return $knownType;
                }
            }
        }

        return $rawType;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFieldsForType(string $typeDemande): array
    {
        $fieldsMap = [
            'Conge' => [
                [
                    'key' => 'typeConge',
                    'label' => 'Type de conge',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Conge annuel',
                        'Conge maladie',
                        'Conge sans solde',
                        'Conge maternite',
                        'Conge paternite',
                        'Conge exceptionnel'
                    ]
                ],
                [
                    'key' => 'dateDebut',
                    'label' => 'Date de debut',
                    'type' => 'date',
                    'required' => true
                ],
                [
                    'key' => 'dateFin',
                    'label' => 'Date de fin',
                    'type' => 'date',
                    'required' => true
                ],
                [
                    'key' => 'nombreJours',
                    'label' => 'Nombre de jours',
                    'type' => 'number',
                    'required' => true
                ],
                [
                    'key' => 'motif',
                    'label' => 'Motif',
                    'type' => 'textarea',
                    'required' => false
                ]
            ],

            'Attestation de travail' => [
                [
                    'key' => 'nombreExemplaires',
                    'label' => 'Nombre d exemplaires',
                    'type' => 'number',
                    'required' => true
                ],
                [
                    'key' => 'motifAttestation',
                    'label' => 'Motif de la demande',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Demarches administratives',
                        'Banque',
                        'Visa',
                        'Location immobiliere',
                        'Autre'
                    ]
                ],
                [
                    'key' => 'destinataire',
                    'label' => 'Destinataire',
                    'type' => 'text',
                    'required' => false
                ]
            ],

            'Attestation de salaire' => [
                [
                    'key' => 'nombreExemplaires',
                    'label' => 'Nombre d exemplaires',
                    'type' => 'number',
                    'required' => true
                ],
                [
                    'key' => 'periode',
                    'label' => 'Periode concernee',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Dernier mois',
                        '3 derniers mois',
                        '6 derniers mois',
                        'Annee en cours',
                        'Annee precedente'
                    ]
                ],
                [
                    'key' => 'motif',
                    'label' => 'Motif',
                    'type' => 'text',
                    'required' => false
                ]
            ],

            'Certificat de travail' => [
                [
                    'key' => 'nombreExemplaires',
                    'label' => 'Nombre d exemplaires',
                    'type' => 'number',
                    'required' => true
                ],
                [
                    'key' => 'motif',
                    'label' => 'Motif',
                    'type' => 'text',
                    'required' => false
                ]
            ],

            'Mutation' => [
                [
                    'key' => 'departementActuel',
                    'label' => 'Departement actuel',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'departementSouhaite',
                    'label' => 'Departement souhaite',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'lieuMutation',
                    'label' => 'Lieu de mutation',
                    'type' => 'location',
                    'required' => true
                ],
                [
                    'key' => 'posteSouhaite',
                    'label' => 'Poste souhaite',
                    'type' => 'text',
                    'required' => false
                ],
                [
                    'key' => 'motif',
                    'label' => 'Motif de la demande',
                    'type' => 'textarea',
                    'required' => true
                ]
            ],

            'Demission' => [
                [
                    'key' => 'dateSouhaitee',
                    'label' => 'Date de depart souhaitee',
                    'type' => 'date',
                    'required' => true
                ],
                [
                    'key' => 'preavis',
                    'label' => 'Duree de preavis',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        '1 mois',
                        '2 mois',
                        '3 mois',
                        'Dispense demandee'
                    ]
                ],
                [
                    'key' => 'motif',
                    'label' => 'Motif de depart',
                    'type' => 'textarea',
                    'required' => false
                ]
            ],

            'Avance sur salaire' => [
                [
                    'key' => 'montant',
                    'label' => 'Montant demande (TND)',
                    'type' => 'number',
                    'required' => true
                ],
                [
                    'key' => 'modaliteRemboursement',
                    'label' => 'Modalite de remboursement',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        '1 mois',
                        '2 mois',
                        '3 mois',
                        '6 mois'
                    ]
                ],
                [
                    'key' => 'motif',
                    'label' => 'Motif de la demande',
                    'type' => 'textarea',
                    'required' => true
                ]
            ],

            'Remboursement' => [
                [
                    'key' => 'typeRemboursement',
                    'label' => 'Type de remboursement',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Frais de transport',
                        'Frais de mission',
                        'Frais de formation',
                        'Frais medicaux',
                        'Autre'
                    ]
                ],
                [
                    'key' => 'montant',
                    'label' => 'Montant (TND)',
                    'type' => 'number',
                    'required' => true
                ],
                [
                    'key' => 'dateDepense',
                    'label' => 'Date de la depense',
                    'type' => 'date',
                    'required' => true
                ],
                [
                    'key' => 'justificatif',
                    'label' => 'Justificatif joint',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Oui',
                        'Non - a fournir'
                    ]
                ],
                [
                    'key' => 'details',
                    'label' => 'Details',
                    'type' => 'textarea',
                    'required' => false
                ]
            ],

            'Materiel de bureau' => [
                [
                    'key' => 'typeMateriel',
                    'label' => 'Type de materiel',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Fournitures',
                        'Mobilier',
                        'Equipement',
                        'Autre'
                    ]
                ],
                [
                    'key' => 'descriptionMateriel',
                    'label' => 'Description du materiel',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'quantite',
                    'label' => 'Quantite',
                    'type' => 'number',
                    'required' => true
                ],
                [
                    'key' => 'urgence',
                    'label' => 'Urgence',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        'Normale',
                        'Urgente',
                        'Tres urgente'
                    ]
                ]
            ],

            'Badge acces' => [
                [
                    'key' => 'motifBadge',
                    'label' => 'Motif de la demande',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Nouveau badge',
                        'Badge perdu',
                        'Badge defectueux',
                        'Extension acces'
                    ]
                ],
                [
                    'key' => 'zonesAcces',
                    'label' => 'Zones acces demandees',
                    'type' => 'text',
                    'required' => false
                ]
            ],

            'Carte de visite' => [
                [
                    'key' => 'quantiteCarte',
                    'label' => 'Quantite',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        '50',
                        '100',
                        '200',
                        '500'
                    ]
                ],
                [
                    'key' => 'titreFonction',
                    'label' => 'Titre/Fonction a afficher',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'telephone',
                    'label' => 'Numero de telephone',
                    'type' => 'text',
                    'required' => false
                ],
                [
                    'key' => 'email',
                    'label' => 'Email',
                    'type' => 'text',
                    'required' => false
                ]
            ],

            'Materiel informatique' => [
                [
                    'key' => 'typeMaterielInfo',
                    'label' => 'Type de materiel',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Ordinateur portable',
                        'Ordinateur fixe',
                        'Ecran',
                        'Clavier/Souris',
                        'Casque',
                        'Webcam',
                        'Autre'
                    ]
                ],
                [
                    'key' => 'motifMateriel',
                    'label' => 'Motif',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Nouveau besoin',
                        'Remplacement',
                        'Mise a niveau'
                    ]
                ],
                [
                    'key' => 'specifications',
                    'label' => 'Specifications souhaitees',
                    'type' => 'textarea',
                    'required' => false
                ]
            ],

            'Acces systeme' => [
                [
                    'key' => 'systeme',
                    'label' => 'Systeme/Application',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'typeAcces',
                    'label' => 'Type acces',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Lecture seule',
                        'Lecture/Ecriture',
                        'Administrateur'
                    ]
                ],
                [
                    'key' => 'justification',
                    'label' => 'Justification',
                    'type' => 'textarea',
                    'required' => true
                ]
            ],

            'Logiciel' => [
                [
                    'key' => 'nomLogiciel',
                    'label' => 'Nom du logiciel',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'version',
                    'label' => 'Version',
                    'type' => 'text',
                    'required' => false
                ],
                [
                    'key' => 'typeLicence',
                    'label' => 'Type de licence',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        'Achat',
                        'Abonnement mensuel',
                        'Abonnement annuel',
                        'Open source'
                    ]
                ],
                [
                    'key' => 'justificationLogiciel',
                    'label' => 'Justification du besoin',
                    'type' => 'textarea',
                    'required' => true
                ]
            ],

            'Probleme technique' => [
                [
                    'key' => 'typeProbleme',
                    'label' => 'Type de probleme',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Materiel',
                        'Logiciel',
                        'Reseau',
                        'Email',
                        'Imprimante',
                        'Autre'
                    ]
                ],
                [
                    'key' => 'descriptionProbleme',
                    'label' => 'Description du probleme',
                    'type' => 'textarea',
                    'required' => true
                ],
                [
                    'key' => 'impact',
                    'label' => 'Impact sur le travail',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Bloquant',
                        'Important',
                        'Modere',
                        'Faible'
                    ]
                ]
            ],

            'Formation interne' => [
                [
                    'key' => 'nomFormation',
                    'label' => 'Nom de la formation',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'formateur',
                    'label' => 'Formateur',
                    'type' => 'text',
                    'required' => false
                ],
                [
                    'key' => 'dateSouhaiteeFormation',
                    'label' => 'Date souhaitee',
                    'type' => 'date',
                    'required' => false
                ],
                [
                    'key' => 'objectifFormation',
                    'label' => 'Objectif de la formation',
                    'type' => 'textarea',
                    'required' => true
                ]
            ],

            'Formation externe' => [
                [
                    'key' => 'nomFormationExt',
                    'label' => 'Nom de la formation',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'organisme',
                    'label' => 'Organisme de formation',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'lieuFormation',
                    'label' => 'Lieu de formation',
                    'type' => 'location',
                    'required' => true
                ],
                [
                    'key' => 'duree',
                    'label' => 'Duree',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'cout',
                    'label' => 'Cout estime (TND)',
                    'type' => 'number',
                    'required' => false
                ],
                [
                    'key' => 'dateDebutFormation',
                    'label' => 'Date de debut souhaitee',
                    'type' => 'date',
                    'required' => false
                ],
                [
                    'key' => 'objectif',
                    'label' => 'Objectif et benefices attendus',
                    'type' => 'textarea',
                    'required' => true
                ]
            ],

            'Certification' => [
                [
                    'key' => 'nomCertification',
                    'label' => 'Nom de la certification',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'organismeCertif',
                    'label' => 'Organisme certificateur',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'lieuExamen',
                    'label' => 'Lieu examen',
                    'type' => 'location',
                    'required' => true
                ],
                [
                    'key' => 'coutCertif',
                    'label' => 'Cout (TND)',
                    'type' => 'number',
                    'required' => false
                ],
                [
                    'key' => 'datePassage',
                    'label' => 'Date de passage souhaitee',
                    'type' => 'date',
                    'required' => false
                ],
                [
                    'key' => 'justificationCertif',
                    'label' => 'Justification',
                    'type' => 'textarea',
                    'required' => true
                ]
            ],

            'Teletravail' => [
                [
                    'key' => 'typeTeletravail',
                    'label' => 'Type de demande',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Teletravail regulier',
                        'Teletravail occasionnel',
                        'Teletravail exceptionnel'
                    ]
                ],
                [
                    'key' => 'joursParSemaine',
                    'label' => 'Jours par semaine',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        '1 jour',
                        '2 jours',
                        '3 jours',
                        '4 jours',
                        'Temps plein'
                    ]
                ],
                [
                    'key' => 'joursSouhaites',
                    'label' => 'Jours souhaites',
                    'type' => 'text',
                    'required' => false
                ],
                [
                    'key' => 'adresseTeletravail',
                    'label' => 'Adresse de teletravail',
                    'type' => 'location',
                    'required' => true
                ],
                [
                    'key' => 'dateDebutTeletravail',
                    'label' => 'Date de debut',
                    'type' => 'date',
                    'required' => true
                ],
                [
                    'key' => 'dateFinTeletravail',
                    'label' => 'Date de fin',
                    'type' => 'date',
                    'required' => false
                ],
                [
                    'key' => 'motifTeletravail',
                    'label' => 'Motif',
                    'type' => 'textarea',
                    'required' => false
                ]
            ],

            'Changement horaires' => [
                [
                    'key' => 'horairesActuels',
                    'label' => 'Horaires actuels',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'horairesSouhaites',
                    'label' => 'Horaires souhaites',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'dateDebutHoraires',
                    'label' => 'Date de debut',
                    'type' => 'date',
                    'required' => true
                ],
                [
                    'key' => 'dureeChangement',
                    'label' => 'Duree',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        'Temporaire - 1 mois',
                        'Temporaire - 3 mois',
                        'Temporaire - 6 mois',
                        'Permanent'
                    ]
                ],
                [
                    'key' => 'motifHoraires',
                    'label' => 'Motif',
                    'type' => 'textarea',
                    'required' => true
                ]
            ],

            'Heures supplementaires' => [
                [
                    'key' => 'dateHeuresSup',
                    'label' => 'Date',
                    'type' => 'date',
                    'required' => true
                ],
                [
                    'key' => 'nombreHeures',
                    'label' => 'Nombre heures',
                    'type' => 'number',
                    'required' => true
                ],
                [
                    'key' => 'heureDebut',
                    'label' => 'Heure de debut',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'heureFin',
                    'label' => 'Heure de fin',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'motifHeuresSup',
                    'label' => 'Motif/Projet',
                    'type' => 'textarea',
                    'required' => true
                ],
                [
                    'key' => 'valideParResponsable',
                    'label' => 'Valide par responsable',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Oui',
                        'En attente de validation'
                    ]
                ]
            ],

            'Autre' => [
                [
                    'key' => 'besoinPersonnalise',
                    'label' => 'Nom de votre demande',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'descriptionBesoin',
                    'label' => 'Description detaillee du besoin',
                    'type' => 'textarea',
                    'required' => true
                ],
                [
                    'key' => 'niveauUrgenceAutre',
                    'label' => 'Niveau d urgence',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'Faible',
                        'Normale',
                        'Urgente',
                        'Tres urgente'
                    ]
                ],
                [
                    'key' => 'dateSouhaiteeAutre',
                    'label' => 'Date souhaitee',
                    'type' => 'date',
                    'required' => false
                ],
                [
                    'key' => 'pieceOuContexte',
                    'label' => 'Contexte ou precision supplementaire',
                    'type' => 'textarea',
                    'required' => false
                ]
            ]
        ];

        $resolvedType = $this->resolveCanonicalType($typeDemande);
        $normalizedType = $this->normalizeTypeKey($resolvedType ?? $typeDemande);
        foreach ($fieldsMap as $knownType => $fields) {
            if ($this->normalizeTypeKey($knownType) === $normalizedType) {
                return $fields;
            }
        }

        return [];
    }

    public function getFieldLabel(string $typeDemande, string $fieldKey): string
    {
        $fields = $this->getFieldsForType($typeDemande);

        foreach ($fields as $field) {
            if (($field['key'] ?? '') === $fieldKey) {
                return $field['label'];
            }
        }

        return $fieldKey;
    }

    private function findOwningCategoryForType(string $typeDemande): ?string
    {
        $rawType = trim($typeDemande);
        if ('' === $rawType) {
            return null;
        }

        $normalizedType = $this->normalizeTypeKey($rawType);
        $resolvedType = self::TYPE_ALIASES[$normalizedType] ?? null;

        foreach (self::CATEGORY_TYPES as $category => $types) {
            foreach ($types as $knownType) {
                if ($knownType === $resolvedType || $this->normalizeTypeKey($knownType) === $normalizedType) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function normalizeTypeKey(string $value): string
    {
        $normalized = trim(mb_strtolower($value));
        $replacements = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ];

        $normalized = strtr($normalized, $replacements);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $normalized;
    }
}
