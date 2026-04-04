<?php

namespace App\Entity;

use App\Repository\EquipeProjetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipeProjetRepository::class)]
#[ORM\Table(name: 'equipe_projet')]
class EquipeProjet
{
    #[ORM\Id]
    #[ORM\Column(name: 'id_projet')]
    private ?int $projetId = null;

    #[ORM\Id]
    #[ORM\Column(name: 'id_employe')]
    private ?int $employeId = null;

    public function getProjetId(): ?int
    {
        return $this->projetId;
    }

    public function setProjetId(?int $projetId): static
    {
        $this->projetId = $projetId;

        return $this;
    }

    public function getEmployeId(): ?int
    {
        return $this->employeId;
    }

    public function setEmployeId(?int $employeId): static
    {
        $this->employeId = $employeId;

        return $this;
    }
}
