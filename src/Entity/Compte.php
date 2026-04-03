<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\CompteRepository;

#[ORM\Entity(repositoryClass: CompteRepository::class)]
#[ORM\Table(name: 'compte')]
class Compte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_compte = null;

    public function getId_compte(): ?int
    {
        return $this->id_compte;
    }

    public function setId_compte(int $id_compte): self
    {
        $this->id_compte = $id_compte;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $e_mail = null;

    public function getE_mail(): ?string
    {
        return $this->e_mail;
    }

    public function setE_mail(string $e_mail): self
    {
        $this->e_mail = $e_mail;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $mot_de_passe = null;

    public function getMot_de_passe(): ?string
    {
        return $this->mot_de_passe;
    }

    public function setMot_de_passe(string $mot_de_passe): self
    {
        $this->mot_de_passe = $mot_de_passe;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Employé::class, inversedBy: 'comptes')]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe')]
    private ?Employé $employé = null;

    public function getEmployé(): ?Employé
    {
        return $this->employé;
    }

    public function setEmployé(?Employé $employé): self
    {
        $this->employé = $employé;
        return $this;
    }

}
