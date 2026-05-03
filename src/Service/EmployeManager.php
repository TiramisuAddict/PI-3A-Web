<?php

namespace App\Service;

use App\Entity\Employe;

class EmployeManager
{
    public function validate(Employe $employe): bool
    {
        if ($employe->getNom() === '') {
            throw new \InvalidArgumentException('Le nom est obligatoire');
        }

        if ($employe->getPrenom() === '') {
            throw new \InvalidArgumentException('Le prénom est obligatoire');
        }

        $email = $employe->getEmail();

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !str_ends_with(strtolower($email), '@gmail.com')) {
            throw new \InvalidArgumentException('Email invalide: il doit se terminer par @gmail.com');
        }

        $telephone = $employe->getTelephone();

        if ($telephone < 10000000 || $telephone > 99999999) {
            throw new \InvalidArgumentException('Le téléphone doit contenir exactement 8 chiffres');
        }

        if ($employe->getPoste() === '') {
            throw new \InvalidArgumentException('Le poste est obligatoire');
        }

        if ($employe->getRole() === '') {
            throw new \InvalidArgumentException('Le rôle est obligatoire');
        }

        $dateEmbauche = $employe->getDateEmbauche();
        if ($dateEmbauche !== null) {
            $d = clone $dateEmbauche;
            $today = new \DateTimeImmutable('today');
            $candidate = $d instanceof \DateTimeImmutable ? $d : \DateTimeImmutable::createFromInterface($d);

            if ($candidate > $today) {
                throw new \InvalidArgumentException("La date d'embauche doit être inférieure ou égale à la date d'aujourd'hui");
            }
        }

        return true;
    }
}