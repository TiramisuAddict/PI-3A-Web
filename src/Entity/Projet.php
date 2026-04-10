<?php

namespace App\Entity;

use App\Repository\ProjetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjetRepository::class)]
#[ORM\Table(name: 'projet')]
class Projet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_projet')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(name: 'responsable_id', referencedColumnName: 'id_employe', nullable: false, onDelete: 'CASCADE')]
    private ?Employe $responsable = null;

    #[ORM\Column(length: 255)]
    private string $nom = '';

    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\Column(name: 'date_debut', type: 'date')]
    private \DateTimeInterface $dateDebut;

    #[ORM\Column(name: 'date_fin_prevue', type: 'date')]
    private \DateTimeInterface $dateFinPrevue;

    #[ORM\Column(name: 'date_fin_reelle', type: 'date', nullable: true)]
    private ?\DateTimeInterface $dateFinReelle = null;

    #[ORM\Column(length: 255)]
    private string $statut = '';

    #[ORM\Column(length: 255)]
    private string $priorite = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResponsable(): ?Employe
    {
        return $this->responsable;
    }

    public function setResponsable(?Employe $responsable): static
    {
        $this->responsable = $responsable;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

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

    public function getDateDebut(): \DateTimeInterface
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeInterface $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFinPrevue(): \DateTimeInterface
    {
        return $this->dateFinPrevue;
    }

    public function setDateFinPrevue(\DateTimeInterface $dateFinPrevue): static
    {
        $this->dateFinPrevue = $dateFinPrevue;

        return $this;
    }

    public function getDateFinReelle(): ?\DateTimeInterface
    {
        return $this->dateFinReelle;
    }

    public function setDateFinReelle(?\DateTimeInterface $dateFinReelle): static
    {
        $this->dateFinReelle = $dateFinReelle;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

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
}
