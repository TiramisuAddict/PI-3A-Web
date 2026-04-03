<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\DemandeDetailRepository;

#[ORM\Entity(repositoryClass: DemandeDetailRepository::class)]
#[ORM\Table(name: 'demande_details')]
class DemandeDetail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_details = null;

    public function getId_details(): ?int
    {
        return $this->id_details;
    }

    public function setId_details(int $id_details): self
    {
        $this->id_details = $id_details;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Demande::class, inversedBy: 'demandeDetails')]
    #[ORM\JoinColumn(name: 'id_demande', referencedColumnName: 'id_demande')]
    private ?Demande $demande = null;

    public function getDemande(): ?Demande
    {
        return $this->demande;
    }

    public function setDemande(?Demande $demande): self
    {
        $this->demande = $demande;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: false)]
    private ?string $details = null;

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(string $details): self
    {
        $this->details = $details;
        return $this;
    }

}
