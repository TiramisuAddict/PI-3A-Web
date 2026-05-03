<?php

namespace App\Service;

use App\Entity\Employe;

class EmployeManager
{
    private function readRequiredString(callable $getter, string $message): string
    {
        try {
            $value = $getter();
        } catch (\Error) {
            throw new \InvalidArgumentException($message);
        }

        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    private function readTelephone(Employe $employe): int
    {
        try {
            $telephone = $employe->getTelephone();
        } catch (\Error) {
            throw new \InvalidArgumentException('Le téléphone doit contenir exactement 8 chiffres');
        }

        if ($telephone < 10000000 || $telephone > 99999999) {
            throw new \InvalidArgumentException('Le téléphone doit contenir exactement 8 chiffres');
        }

        return $telephone;
    }

    public function validate(Employe $employe): bool
    {
        $this->readRequiredString(static fn() => $employe->getNom(), 'Le nom est obligatoire');
        $this->readRequiredString(static fn() => $employe->getPrenom(), 'Le prénom est obligatoire');
        $email = $this->readRequiredString(static fn() => $employe->getEmail(), 'Email invalide: il doit se terminer par @gmail.com');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !str_ends_with(strtolower($email), '@gmail.com')) {
            throw new \InvalidArgumentException('Email invalide: il doit se terminer par @gmail.com');
        }

        $this->readTelephone($employe);
        $this->readRequiredString(static fn() => $employe->getPoste(), 'Le poste est obligatoire');
        $this->readRequiredString(static fn() => $employe->getRole(), 'Le rôle est obligatoire');

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