<?php
namespace App\Service;

use App\Entity\Offre;

class OffreManager
{
    public function validate(Offre $offre): bool
    {
        if (empty($offre->getTitrePoste())) {
            throw new \InvalidArgumentException('Le titre du poste est obligatoire');
        }

        if ($offre->getDateLimite() !== null && $offre->getDateLimite() <= new \DateTime()) {
            throw new \InvalidArgumentException('La date limite doit être dans le futur');
        }

        return true;
    }
}