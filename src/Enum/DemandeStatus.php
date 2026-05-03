<?php

namespace App\Enum;

enum DemandeStatus: string
{
    case NOUVELLE = 'Nouvelle';
    case EN_COURS = 'En cours';
    case EN_ATTENTE = 'En attente';
    case RESOLUE = 'Resolue';
    case REJETEE = 'Rejetee';
    case RECONSIDERATION = 'Reconsideration';
    case FERMEE = 'Fermee';
    case ANNULEE = 'Annulee';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
