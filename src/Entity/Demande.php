<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\DemandeRepository;

#[ORM\Entity(repositoryClass: DemandeRepository::class)]
#[ORM\Table(name: 'demande')]
class Demande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_demande = null;

    #[ORM\ManyToOne(targetEntity: Employe::class, inversedBy: 'demandes')]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe')]
    private ?Employe $employe = null;

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $categorie = null;

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $titre = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $priorite = null;

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $status = null;

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $date_creation = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $type_demande = null;

    #[ORM\OneToMany(targetEntity: DemandeDetail::class, mappedBy: 'demande', cascade: ['remove'])]
    private Collection $demandeDetails;

    #[ORM\OneToMany(targetEntity: HistoriqueDemande::class, mappedBy: 'demande', cascade: ['remove'])]
    private Collection $historiqueDemandes;

    public function __construct()
    {
        $this->demandeDetails = new ArrayCollection();
        $this->historiqueDemandes = new ArrayCollection();
    }

    public function getIdDemande(): ?int
    {
        return $this->id_demande;
    }

    public function getEmploye(): ?Employe
    {
        return $this->employe;
    }

    public function setEmploye(?Employe $employe): self
    {
        $this->employe = $employe;
        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): self
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): self
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

    public function setPriorite(?string $priorite): self
    {
        $this->priorite = $priorite;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->date_creation;
    }

    public function setDateCreation(\DateTimeInterface $date_creation): self
    {
        $this->date_creation = $date_creation;
        return $this;
    }

    public function getTypeDemande(): ?string
    {
        return $this->type_demande;
    }

    public function setTypeDemande(?string $type_demande): self
    {
        $this->type_demande = $type_demande;
        return $this;
    }

    public function getDemandeDetails(): Collection
    {
        return $this->demandeDetails;
    }

    public function addDemandeDetail(DemandeDetail $demandeDetail): self
    {
        if (!$this->demandeDetails->contains($demandeDetail)) {
            $this->demandeDetails->add($demandeDetail);
            $demandeDetail->setDemande($this);
        }
        return $this;
    }

    public function removeDemandeDetail(DemandeDetail $demandeDetail): self
    {
        if ($this->demandeDetails->removeElement($demandeDetail)) {
            if ($demandeDetail->getDemande() === $this) {
                $demandeDetail->setDemande(null);
            }
        }
        return $this;
    }

    public function getHistoriqueDemandes(): Collection
    {
        return $this->historiqueDemandes;
    }

    public function addHistoriqueDemande(HistoriqueDemande $historiqueDemande): self
    {
        if (!$this->historiqueDemandes->contains($historiqueDemande)) {
            $this->historiqueDemandes->add($historiqueDemande);
            $historiqueDemande->setDemande($this);
        }
        return $this;
    }

    public function removeHistoriqueDemande(HistoriqueDemande $historiqueDemande): self
    {
        if ($this->historiqueDemandes->removeElement($historiqueDemande)) {
            if ($historiqueDemande->getDemande() === $this) {
                $historiqueDemande->setDemande(null);
            }
        }
        return $this;
    }
}
