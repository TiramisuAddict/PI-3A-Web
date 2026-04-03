<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\TacheRepository;

#[ORM\Entity(repositoryClass: TacheRepository::class)]
#[ORM\Table(name: 'tache')]
class Tache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_tache = null;

    public function getId_tache(): ?int
    {
        return $this->id_tache;
    }

    public function setId_tache(int $id_tache): self
    {
        $this->id_tache = $id_tache;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Projet::class, inversedBy: 'taches')]
    #[ORM\JoinColumn(name: 'id_projet', referencedColumnName: 'id_projet')]
    private ?Projet $projet = null;

    public function getProjet(): ?Projet
    {
        return $this->projet;
    }

    public function setProjet(?Projet $projet): self
    {
        $this->projet = $projet;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Employé::class, inversedBy: 'taches')]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe')]
    private ?Employé $employé = null;

    public function getEmploye(): ?Employé
    {
        return $this->employé;
    }

    public function setEmploye(?Employé $employe): self
    {
        $this->employé = $employe;
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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $statut_tache = null;

    public function getStatut_tache(): ?string
    {
        return $this->statut_tache;
    }

    public function setStatut_tache(string $statut_tache): self
    {
        $this->statut_tache = $statut_tache;
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

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $date_deb = null;

    public function getDate_deb(): ?\DateTimeInterface
    {
        return $this->date_deb;
    }

    public function setDate_deb(\DateTimeInterface $date_deb): self
    {
        $this->date_deb = $date_deb;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $date_limite = null;

    public function getDate_limite(): ?\DateTimeInterface
    {
        return $this->date_limite;
    }

    public function setDate_limite(\DateTimeInterface $date_limite): self
    {
        $this->date_limite = $date_limite;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $progression = null;

    public function getProgression(): ?int
    {
        return $this->progression;
    }

    public function setProgression(int $progression): self
    {
        $this->progression = $progression;
        return $this;
    }

    public function getIdTache(): ?int
    {
        return $this->id_tache;
    }

    public function getStatutTache(): ?string
    {
        return $this->statut_tache;
    }

    public function setStatutTache(string $statut_tache): static
    {
        $this->statut_tache = $statut_tache;

        return $this;
    }

    public function getDateDeb(): ?\DateTime
    {
        return $this->date_deb;
    }

    public function setDateDeb(\DateTime $date_deb): static
    {
        $this->date_deb = $date_deb;

        return $this;
    }

    public function getDateLimite(): ?\DateTime
    {
        return $this->date_limite;
    }

    public function setDateLimite(\DateTime $date_limite): static
    {
        $this->date_limite = $date_limite;

        return $this;
    }

    public function getEmployé(): ?Employé
    {
        return $this->employé;
    }

    public function setEmployé(?Employé $employé): static
    {
        $this->employé = $employé;

        return $this;
    }

}
