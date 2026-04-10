<?php

namespace App\Entity;

use App\Repository\TacheRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TacheRepository::class)]
#[ORM\Table(name: 'tache')]
class Tache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_tache')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Projet::class)]
    #[ORM\JoinColumn(name: 'id_projet', referencedColumnName: 'id_projet', nullable: false, onDelete: 'CASCADE')]
    private ?Projet $projet = null;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe', nullable: false, onDelete: 'CASCADE')]
    private ?Employe $employe = null;

    #[ORM\Column(length: 255)]
    private string $titre = '';

    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\Column(name: 'statut_tache', length: 255)]
    private string $statutTache = '';

    #[ORM\Column(length: 255)]
    private string $priorite = '';

    #[ORM\Column(name: 'date_deb', type: 'date')]
    private \DateTimeInterface $dateDeb;

    #[ORM\Column(name: 'date_limite', type: 'date')]
    private \DateTimeInterface $dateLimite;

    #[ORM\Column(type: 'smallint')]
    private int $progression = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProjet(): ?Projet
    {
        return $this->projet;
    }

    public function setProjet(?Projet $projet): static
    {
        $this->projet = $projet;

        return $this;
    }

    public function getEmploye(): ?Employe
    {
        return $this->employe;
    }

    public function setEmploye(?Employe $employe): static
    {
        $this->employe = $employe;

        return $this;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatutTache(): string
    {
        return $this->statutTache;
    }

    public function setStatutTache(string $statutTache): static
    {
        $this->statutTache = $statutTache;

        return $this;
    }

    public function getPriorite(): string
    {
        return $this->priorite;
    }

    public function setPriorite(string $priorite): static
    {
        $this->priorite = $priorite;

        return $this;
    }

    public function getDateDeb(): \DateTimeInterface
    {
        return $this->dateDeb;
    }

    public function setDateDeb(\DateTimeInterface $dateDeb): static
    {
        $this->dateDeb = $dateDeb;

        return $this;
    }

    public function getDateLimite(): \DateTimeInterface
    {
        return $this->dateLimite;
    }

    public function setDateLimite(\DateTimeInterface $dateLimite): static
    {
        $this->dateLimite = $dateLimite;

        return $this;
    }

    public function getProgression(): int
    {
        return $this->progression;
    }

    public function setProgression(int $progression): static
    {
        $this->progression = $progression;

        return $this;
    }
}
