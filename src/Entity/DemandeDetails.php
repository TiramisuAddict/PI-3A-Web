<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'demande_details')]
class DemandeDetails
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_details', type: 'integer')]
    private ?int $idDetails = null;

    #[ORM\OneToOne(inversedBy: 'details')]
    #[ORM\JoinColumn(name: 'id_demande', referencedColumnName: 'id_demande', nullable: false)]
    private ?Demande $demande = null;

    #[ORM\Column(name: 'details', type: 'text')]
    private ?string $detailsJson = null;

    public function getId(): ?int
    {
        return $this->idDetails;
    }

    public function getIdDetails(): ?int
    {
        return $this->idDetails;
    }

    public function getDemande(): ?Demande
    {
        return $this->demande;
    }

    public function setDemande(Demande $demande): self
    {
        $this->demande = $demande;
        return $this;
    }

    public function getDetails(): array
    {
        if (empty($this->detailsJson)) {
            return [];
        }
        $decoded = json_decode($this->detailsJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setDetails(array $details): self
    {
        $this->detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
        return $this;
    }

    public function getDetailsJson(): ?string
    {
        return $this->detailsJson;
    }

    public function setDetailsJson(?string $detailsJson): self
    {
        $this->detailsJson = $detailsJson;
        return $this;
    }
}