<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\HistoriqueDemandeRepository;

#[ORM\Entity(repositoryClass: HistoriqueDemandeRepository::class)]
#[ORM\Table(name: 'historique_demande')]
class HistoriqueDemande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    /** @phpstan-ignore-next-line */
    private ?int $id_historique = null;

    #[ORM\ManyToOne(targetEntity: Demande::class, inversedBy: 'historiqueDemandes')]
    #[ORM\JoinColumn(name: 'demande_id', referencedColumnName: 'id_demande', nullable: false, onDelete: 'CASCADE')]
    private ?Demande $demande = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $ancien_statut = null;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $nouveau_statut = '';

    #[ORM\Column(type: 'datetime', nullable: false)]
    private \DateTimeInterface $date_action;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $acteur = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    public function __construct()
    {
        $this->date_action = new \DateTimeImmutable();
    }

    public function getIdHistorique(): ?int
    {
        return $this->id_historique;
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

    public function getAncienStatut(): ?string
    {
        return $this->ancien_statut;
    }

    public function setAncienStatut(?string $ancien_statut): self
    {
        $this->ancien_statut = $ancien_statut;
        return $this;
    }

    public function getNouveauStatut(): string
    {
        return $this->nouveau_statut;
    }

    public function setNouveauStatut(string $nouveau_statut): self
    {
        $this->nouveau_statut = $nouveau_statut;
        return $this;
    }

    public function getDateAction(): \DateTimeInterface
    {
        return $this->date_action;
    }

    public function setDateAction(\DateTimeInterface $date_action): self
    {
        $this->date_action = $date_action;
        return $this;
    }

    public function getActeur(): ?string
    {
        return $this->acteur;
    }

    public function setActeur(?string $acteur): self
    {
        $this->acteur = $acteur;
        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): self
    {
        $this->commentaire = $commentaire;
        return $this;
    }
}
