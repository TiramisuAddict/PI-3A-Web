<?php

namespace App\Service;

use App\Entity\Tache;

class TacheManager
{
    public function validate(Tache $tache): bool
    {
        if (trim($tache->getTitre()) === '') {
            throw new \InvalidArgumentException('Le titre est obligatoire.');
        }

        if (mb_strlen(trim($tache->getTitre())) > 150) {
            throw new \InvalidArgumentException('Le titre ne peut pas dépasser 150 caractères.');
        }

        $dateDeb = $tache->getDate_deb();
        $dateLimite = $tache->getDate_limite();

        if ($dateLimite < $dateDeb) {
            throw new \InvalidArgumentException('La date limite ne peut pas être antérieure à la date de début.');
        }

        return true;
    }
}
