<?php

namespace App\Entity;

use App\Repository\VisiteurRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VisiteurRepository::class)]
#[ORM\Table(name: 'visiteur')]
class Visiteur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_visiteur')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $nom = '';

    #[ORM\Column(length: 255)]
    private string $prenom = '';

    #[ORM\Column(name: 'e_mail', length: 255)]
    private string $email = '';

    #[ORM\Column(name: 'mot_de_passe', length: 255)]
    private string $motDePasse = '';

    #[ORM\Column(type: 'integer')]
    private int $telephone = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
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

    public function getTelephone(): int
    {
        return $this->telephone;
    }

    public function setTelephone(int $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }
}
