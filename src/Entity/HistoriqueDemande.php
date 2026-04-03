<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'historique_demande')]
class HistoriqueDemande
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_historique', type: 'integer')]
    private ?int $idHistorique = null;

    #[ORM\ManyToOne(targetEntity: Demande::class, inversedBy: 'historiques')]
    #[ORM\JoinColumn(name: 'id_demande', referencedColumnName: 'id_demande', nullable: false)]
    private ?Demande $demande = null;

    #[ORM\Column(name: 'ancien_statut', length: 50, nullable: true)]
    private ?string $ancienStatut = null;

    #[ORM\Column(name: 'nouveau_statut', length: 50)]
    private ?string $nouveauStatut = null;

    #[ORM\Column(name: 'date_action', type: 'datetime')]
    private ?\DateTimeInterface $dateAction = null;

    #[ORM\Column(name: 'acteur', length: 100)]
    private ?string $acteur = null;

    #[ORM\Column(name: 'commentaire', type: 'text', nullable: true)]
    private ?string $commentaire = null;

    public function __construct()
    {
        $this->dateAction = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->idHistorique;
    }

    public function getIdHistorique(): ?int
    {
        return $this->idHistorique;
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
        return $this->ancienStatut;
    }

    public function setAncienStatut(?string $ancienStatut): self
    {
        $this->ancienStatut = $ancienStatut;
        return $this;
    }

    public function getNouveauStatut(): ?string
    {
        return $this->nouveauStatut;
    }

    public function setNouveauStatut(string $nouveauStatut): self
    {
        $this->nouveauStatut = $nouveauStatut;
        return $this;
    }

    public function getDateAction(): ?\DateTimeInterface
    {
        return $this->dateAction;
    }

    public function setDateAction(\DateTimeInterface $dateAction): self
    {
        $this->dateAction = $dateAction;
        return $this;
    }

    public function getActeur(): ?string
    {
        return $this->acteur;
    }

    public function setActeur(string $acteur): self
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