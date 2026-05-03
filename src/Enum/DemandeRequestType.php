<?php

namespace App\Enum;

enum DemandeRequestType: string
{
    case CONGE = 'Conge';
    case ATTESTATION_TRAVAIL = 'Attestation de travail';
    case ATTESTATION_SALAIRE = 'Attestation de salaire';
    case CERTIFICAT_TRAVAIL = 'Certificat de travail';
    case MUTATION = 'Mutation';
    case DEMISSION = 'Demission';
    case AVANCE_SUR_SALAIRE = 'Avance sur salaire';
    case REMBOURSEMENT = 'Remboursement';
    case MATERIEL_DE_BUREAU = 'Materiel de bureau';
    case BADGE_ACCES = 'Badge acces';
    case CARTE_DE_VISITE = 'Carte de visite';
    case MATERIEL_INFORMATIQUE = 'Materiel informatique';
    case ACCES_SYSTEME = 'Acces systeme';
    case LOGICIEL = 'Logiciel';
    case PROBLEME_TECHNIQUE = 'Probleme technique';
    case FORMATION_INTERNE = 'Formation interne';
    case FORMATION_EXTERNE = 'Formation externe';
    case CERTIFICATION = 'Certification';
    case TELETRAVAIL = 'Teletravail';
    case CHANGEMENT_HORAIRES = 'Changement horaires';
    case HEURES_SUPPLEMENTAIRES = 'Heures supplementaires';
    case AUTRE = 'Autre';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
