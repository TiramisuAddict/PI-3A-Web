<?php

namespace App\Entity;

use App\Repository\FormationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: FormationRepository::class)]
class Formation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_formation')]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    private ?string $titre = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'L\'organisme est obligatoire.')]
    private ?string $organisme = null;

    #[ORM\Column(name: 'date_debut', type: 'date_immutable')]
    #[Assert\NotNull(message: 'La date de debut est obligatoire.')]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(name: 'date_fin', type: 'date_immutable')]
    #[Assert\NotNull(message: 'La date de fin est obligatoire.')]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Le lieu est obligatoire.')]
    private ?string $lieu = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'La capacite doit etre un nombre positif.')]
    private ?int $capacite = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getOrganisme(): ?string
    {
        return $this->organisme;
    }

    public function setOrganisme(string $organisme): static
    {
        $this->organisme = $organisme;

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function getCapacite(): ?int
    {
        return $this->capacite;
    }

    public function setCapacite(?int $capacite): static
    {
        $this->capacite = $capacite;

        return $this;
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->dateDebut !== null && $this->dateFin !== null && $this->dateFin < $this->dateDebut) {
            $context->buildViolation('La date de fin doit etre apres la date de debut.')
                ->atPath('dateFin')
                ->addViolation();
        }
    }
}
