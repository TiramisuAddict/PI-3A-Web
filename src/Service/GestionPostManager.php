<?php

namespace App\Service;

class GestionPostManager
{
    public function validate(string $titre, string $contenu): bool
    {
        if (trim($titre) === '') {
            throw new \InvalidArgumentException('Le titre du post est obligatoire');
        }

        if (strlen(trim($contenu)) < 10) {
            throw new \InvalidArgumentException('Le contenu du post doit contenir au moins 10 caractères');
        }

        return true;
    }
}