<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ProjetRepository;

#[ORM\Entity(repositoryClass: ProjetRepository::class)]
#[ORM\Table(name: 'projet')]
class Projet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_projet = null;

    public function getId_projet(): ?int
    {
        return $this->id_projet;
    }

    public function setId_projet(int $id_projet): self
    {
        $this->id_projet = $id_projet;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Employe::class, inversedBy: 'projets')]
    #[ORM\JoinColumn(name: 'responsable_id', referencedColumnName: 'id_employe')]
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
    private ?string $nom = null;

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: false)]
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

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $date_debut = null;

    public function getDate_debut(): ?\DateTimeInterface
    {
        return $this->date_debut;
    }

    public function setDate_debut(\DateTimeInterface $date_debut): self
    {
        $this->date_debut = $date_debut;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $date_fin_prevue = null;

    public function getDate_fin_prevue(): ?\DateTimeInterface
    {
        return $this->date_fin_prevue;
    }

    public function setDate_fin_prevue(\DateTimeInterface $date_fin_prevue): self
    {
        $this->date_fin_prevue = $date_fin_prevue;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $date_fin_reelle = null;

    public function getDate_fin_reelle(): ?\DateTimeInterface
    {
        return $this->date_fin_reelle;
    }

    public function setDate_fin_reelle(?\DateTimeInterface $date_fin_reelle): self
    {
        $this->date_fin_reelle = $date_fin_reelle;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $statut = null;

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $priorite = null;

    public function getPriorite(): ?string
    {
        return $this->priorite;
    }

    public function setPriorite(string $priorite): self
    {
        $this->priorite = $priorite;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'projet')]
    private Collection $taches;

    /**
     * @return Collection<int, Tache>
     */
    public function getTaches(): Collection
    {
        if (!$this->taches instanceof Collection) {
            $this->taches = new ArrayCollection();
        }
        return $this->taches;
    }

    public function addTache(Tache $tache): self
    {
        if (!$this->getTaches()->contains($tache)) {
            $this->getTaches()->add($tache);
        }
        return $this;
    }

    public function removeTache(Tache $tache): self
    {
        $this->getTaches()->removeElement($tache);
        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Employe::class, inversedBy: 'projets')]
    #[ORM\JoinTable(
        name: 'equipe_projet',
        joinColumns: [
            new ORM\JoinColumn(name: 'id_projet', referencedColumnName: 'id_projet')
        ],
        inverseJoinColumns: [
            new ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe')
        ]
    )]
    private Collection $employés;

    /**
     * @return Collection<int, Employé>
     */
    public function getEmployés(): Collection
    {
        if (!$this->employés instanceof Collection) {
            $this->employés = new ArrayCollection();
        }
        return $this->employés;
    }

    public function addEmployé(Employe $employé): self
    {
        if (!$this->getEmployés()->contains($employé)) {
            $this->getEmployés()->add($employé);
        }
        return $this;
    }

    public function removeEmployé(Employe $employé): self
    {
        $this->getEmployés()->removeElement($employé);
        return $this;
    }

}