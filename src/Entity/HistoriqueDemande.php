<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\HistoriqueDemandeRepository;

#[ORM\Entity(repositoryClass: HistoriqueDemandeRepository::class)]
#[ORM\Table(name: 'historique_demande')]
class HistoriqueDemande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_historique = null;

    public function getId_historique(): ?int
    {
        return $this->id_historique;
    }

    public function setId_historique(int $id_historique): self
    {
        $this->id_historique = $id_historique;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Demande::class, inversedBy: 'historiqueDemandes')]
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

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $ancien_statut = null;

    public function getAncien_statut(): ?string
    {
        return $this->ancien_statut;
    }

    public function setAncien_statut(?string $ancien_statut): self
    {
        $this->ancien_statut = $ancien_statut;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $nouveau_statut = null;

    public function getNouveau_statut(): ?string
    {
        return $this->nouveau_statut;
    }

    public function setNouveau_statut(string $nouveau_statut): self
    {
        $this->nouveau_statut = $nouveau_statut;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $date_action = null;

    public function getDate_action(): ?\DateTimeInterface
    {
        return $this->date_action;
    }

    public function setDate_action(\DateTimeInterface $date_action): self
    {
        $this->date_action = $date_action;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $acteur = null;

    public function getActeur(): ?string
    {
        return $this->acteur;
    }

    public function setActeur(?string $acteur): self
    {
        $this->acteur = $acteur;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): self
    {
        $this->commentaire = $commentaire;
        return $this;
    }

    public function getIdHistorique(): ?int
    {
        return $this->id_historique;
    }

    public function getAncienStatut(): ?string
    {
        return $this->ancien_statut;
    }

    public function setAncienStatut(?string $ancien_statut): static
    {
        $this->ancien_statut = $ancien_statut;

        return $this;
    }

    public function getNouveauStatut(): ?string
    {
        return $this->nouveau_statut;
    }

    public function setNouveauStatut(string $nouveau_statut): static
    {
        $this->nouveau_statut = $nouveau_statut;

        return $this;
    }

    public function getDateAction(): ?\DateTime
    {
        return $this->date_action;
    }

    public function setDateAction(\DateTime $date_action): static
    {
        $this->date_action = $date_action;

        return $this;
    }

}
