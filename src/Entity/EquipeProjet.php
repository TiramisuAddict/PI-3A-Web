<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

class EquipeProjet
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Projet::class)]
    #[ORM\JoinColumn(name: 'id_projet_id', referencedColumnName: 'id_projet', nullable: false, onDelete: 'CASCADE')]
    private ?Projet $projet = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(name: 'id_employe_id', referencedColumnName: 'id_employe', nullable: false, onDelete: 'CASCADE')]
    private ?Employe $employe = null;

    public function getProjetId(): ?int
    {
        return $this->projet?->getIdProjet();
    }

    public function setProjetId(?int $projetId): static
    {
        return $this;
    }

    public function getEmployeId(): ?int
    {
        return $this->employe?->getId_employe();
    }

    public function setEmployeId(?int $employeId): static
    {
        return $this;
    }

    public function getProjet(): ?Projet
    {
        return $this->projet;
    }

    public function setProjet(?Projet $projet): static
    {
        $this->projet = $projet;

        return $this;
    }

    public function getEmploye(): ?Employe
    {
        return $this->employe;
    }

    public function setEmploye(?Employe $employe): static
    {
        $this->employe = $employe;

        return $this;
    }
}
