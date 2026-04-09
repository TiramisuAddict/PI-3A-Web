<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\InscriptionFormationRepository;

#[ORM\Entity(repositoryClass: InscriptionFormationRepository::class)]
#[ORM\Table(name: 'inscription_formation')]
class InscriptionFormation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_inscription = null;

    public function getId_inscription(): ?int
    {
        return $this->id_inscription;
    }

    public function setId_inscription(int $id_inscription): self
    {
        $this->id_inscription = $id_inscription;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $id_formation = null;

    public function getId_formation(): ?int
    {
        return $this->id_formation;
    }

    public function setId_formation(int $id_formation): self
    {
        $this->id_formation = $id_formation;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $statut = null;

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $id_employe = null;

    public function getId_employe(): ?int
    {
        return $this->id_employe;
    }

    public function setId_employe(int $id_employe): self
    {
        $this->id_employe = $id_employe;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $raison = null;

    public function getRaison(): ?string
    {
        return $this->raison;
    }

    public function setRaison(?string $raison): self
    {
        $this->raison = $raison;
        return $this;
    }

}
