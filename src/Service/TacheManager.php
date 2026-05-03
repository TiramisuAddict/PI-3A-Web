<?php

namespace App\Service;

use App\Entity\Tache;

class TacheManager
{
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
