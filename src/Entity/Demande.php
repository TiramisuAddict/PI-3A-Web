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

    public function getId_demande(): ?int
    {
        return $this->id_demande;
    }

    public function setId_demande(int $id_demande): self
    {
        $this->id_demande = $id_demande;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Employe::class, inversedBy: 'demandes')]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe')]
    private ?Employe $employé = null;

    public function getEmployé(): ?Employe
    {
        return $this->employé;
    }

    public function setEmployé(?Employe $employé): self
    {
        $this->employé = $employé;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $categorie = null;

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(string $categorie): self
    {
        $this->categorie = $categorie;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $titre = null;

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $description = null;

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $priorite = null;

    public function getPriorite(): ?string
    {
        return $this->priorite;
    }

    public function setPriorite(?string $priorite): self
    {
        $this->priorite = $priorite;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $status = null;

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $date_creation = null;

    public function getDate_creation(): ?\DateTimeInterface
    {
        return $this->date_creation;
    }

    public function setDate_creation(\DateTimeInterface $date_creation): self
    {
        $this->date_creation = $date_creation;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $type_demande = null;

    public function getType_demande(): ?string
    {
        return $this->type_demande;
    }

    public function setType_demande(string $type_demande): self
    {
        $this->type_demande = $type_demande;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: DemandeDetail::class, mappedBy: 'demande')]
    private Collection $demandeDetails;

    /**
     * @return Collection<int, DemandeDetail>
     */
    public function getDemandeDetails(): Collection
    {
        if (!$this->demandeDetails instanceof Collection) {
            $this->demandeDetails = new ArrayCollection();
        }
        return $this->demandeDetails;
    }

    public function addDemandeDetail(DemandeDetail $demandeDetail): self
    {
        if (!$this->getDemandeDetails()->contains($demandeDetail)) {
            $this->getDemandeDetails()->add($demandeDetail);
        }
        return $this;
    }

    public function removeDemandeDetail(DemandeDetail $demandeDetail): self
    {
        $this->getDemandeDetails()->removeElement($demandeDetail);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: HistoriqueDemande::class, mappedBy: 'demande')]
    private Collection $historiqueDemandes;

    /**
     * @return Collection<int, HistoriqueDemande>
     */
    public function getHistoriqueDemandes(): Collection
    {
        if (!$this->historiqueDemandes instanceof Collection) {
            $this->historiqueDemandes = new ArrayCollection();
        }
        return $this->historiqueDemandes;
    }

    public function addHistoriqueDemande(HistoriqueDemande $historiqueDemande): self
    {
        if (!$this->getHistoriqueDemandes()->contains($historiqueDemande)) {
            $this->getHistoriqueDemandes()->add($historiqueDemande);
        }
        return $this;
    }

    public function removeHistoriqueDemande(HistoriqueDemande $historiqueDemande): self
    {
        $this->getHistoriqueDemandes()->removeElement($historiqueDemande);
        return $this;
    }

}
