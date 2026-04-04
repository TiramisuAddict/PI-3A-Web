<?php

namespace App\Entity;

use App\Repository\AdministrateurSystemeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdministrateurSystemeRepository::class)]
#[ORM\Table(name: 'administrateur_systeme')]
class AdministrateurSysteme
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'e_mail', length: 255)]
    private string $email = '';

    #[ORM\Column(name: 'mot_de_passe', length: 255)]
    private string $motDePasse = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getMotDePasse(): string
    {
        return $this->motDePasse;
    }

    public function setMotDePasse(string $motDePasse): static
    {
        $this->motDePasse = $motDePasse;

        return $this;
    }
}
