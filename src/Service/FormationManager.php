<?php

namespace App\Service;

use App\Entity\Tache;

class FormationManager
{
    /**
     * Validates a Tache entity against business rules.
     *
     * Rules checked:
     * 1. Title (titre) must not be empty or whitespace-only.
     * 2. Date limite must not be before date_deb (if both are set).
     *
     * @param Tache $tache The task to validate.
     * @return bool True if validation passes.
     * @throws \InvalidArgumentException If any validation rule fails.
     */
    public function validate(Tache $tache): bool
    {
        if (empty(trim((string) $tache->getTitre()))) {
            throw new \InvalidArgumentException('Le titre est obligatoire.');
        }

        $dateDeb = $tache->getDate_deb();
        $dateLimite = $tache->getDate_limite();

        if ($dateDeb !== null && $dateLimite !== null && $dateLimite < $dateDeb) {
            throw new \InvalidArgumentException('La date limite ne peut pas être antérieure à la date de début.');
        }

        return true;
    }
}
