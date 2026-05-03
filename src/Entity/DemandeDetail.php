<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\DemandeDetailRepository;

#[ORM\Entity(repositoryClass: DemandeDetailRepository::class)]
#[ORM\Table(name: 'demande_detail')]
class DemandeDetail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    /** @phpstan-ignore-next-line */
    private ?int $id_details = null;

    #[ORM\ManyToOne(targetEntity: Demande::class, inversedBy: 'demandeDetails')]
    #[ORM\JoinColumn(name: 'demande_id', referencedColumnName: 'id_demande', nullable: false, onDelete: 'CASCADE')]
    private ?Demande $demande = null;

    #[ORM\Column(type: 'text', nullable: false)]
    private string $details = '';

    public function getIdDetails(): ?int
    {
        return $this->id_details;
    }

    public function getDemande(): ?Demande
    {
        return $this->demande;
    }

    public function setDemande(?Demande $demande): self
    {
        $this->demande = $demande;
        return $this;
    }

    public function getDetails(): string
    {
        return $this->details;
    }

    public function setDetails(string $details): self
    {
        $this->details = $details;
        return $this;
    }
}
