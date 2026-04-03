<?php

namespace App\Entity;

use App\Repository\DemandeRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: DemandeRepository::class)]
#[ORM\Table(name: 'demande')]
class Demande
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_demande', type: 'integer')]
    private ?int $idDemande = null;

    #[ORM\Column(name: 'id_employe', type: 'integer')]
    private ?int $idEmploye = null;

    #[ORM\Column(name: 'categorie', length: 100)]
    private ?string $categorie = null;

    #[ORM\Column(name: 'titre', length: 255)]
    private ?string $titre = null;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'priorite', length: 50)]
    private ?string $priorite = null;

    #[ORM\Column(name: 'status', length: 50)]
    private ?string $status = null;

    #[ORM\Column(name: 'date_creation', type: 'date')]
    private ?\DateTimeInterface $dateCreation = null;

    #[ORM\Column(name: 'type_demande', length: 100)]
    private ?string $typeDemande = null;

    #[ORM\OneToOne(mappedBy: 'demande', cascade: ['persist', 'remove'])]
    private ?DemandeDetails $details = null;

    #[ORM\OneToMany(mappedBy: 'demande', targetEntity: HistoriqueDemande::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['dateAction' => 'DESC'])]
    private Collection $historiques;

    public function __construct()
    {
        $this->historiques = new ArrayCollection();
        $this->dateCreation = new \DateTime();
        $this->status = 'Nouvelle';
        $this->priorite = 'NORMALE';
    }

    public function getId(): ?int
    {
        return $this->idDemande;
    }

    public function getIdDemande(): ?int
    {
        return $this->idDemande;
    }

    public function getIdEmploye(): ?int
    {
        return $this->idEmploye;
    }

    public function setIdEmploye(int $idEmploye): self
    {
        $this->idEmploye = $idEmploye;
        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(string $categorie): self
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getPriorite(): ?string
    {
        return $this->priorite;
    }

    public function setPriorite(string $priorite): self
    {
        $this->priorite = $priorite;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeInterface $dateCreation): self
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getTypeDemande(): ?string
    {
        return $this->typeDemande;
    }

    public function setTypeDemande(string $typeDemande): self
    {
        $this->typeDemande = $typeDemande;
        return $this;
    }

    public function getDetails(): ?DemandeDetails
    {
        return $this->details;
    }

    public function setDetails(?DemandeDetails $details): self
    {
        if ($details !== null && $details->getDemande() !== $this) {
            $details->setDemande($this);
        }
        $this->details = $details;
        return $this;
    }

    public function getHistoriques(): Collection
    {
        return $this->historiques;
    }

    public function addHistorique(HistoriqueDemande $historique): self
    {
        if (!$this->historiques->contains($historique)) {
            $this->historiques->add($historique);
            $historique->setDemande($this);
        }
        return $this;
    }
}