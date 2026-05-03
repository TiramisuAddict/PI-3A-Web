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
    private string $titre = '';

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'L\'organisme est obligatoire.')]
    private string $organisme = '';

    #[ORM\Column(name: 'date_debut', type: 'date_immutable')]
    #[Assert\NotNull(message: 'La date de debut est obligatoire.')]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(name: 'date_fin', type: 'date_immutable')]
    #[Assert\NotNull(message: 'La date de fin est obligatoire.')]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Le lieu est obligatoire.')]
    private string $lieu = '';

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero(message: 'La capacite doit etre un nombre positif.')]
    private int $capacite = 0;

    public function __construct()
    {
        $today = new \DateTimeImmutable('today');
        $this->dateDebut = $today;
        $this->dateFin = $today;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getOrganisme(): string
    {
        return $this->organisme;
    }

    public function setOrganisme(string $organisme): static
    {
        $this->organisme = $organisme;

        return $this;
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getLieu(): string
    {
        return $this->lieu;
    }

    public function setLieu(string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function getCapacite(): int
    {
        return $this->capacite;
    }

    public function setCapacite(int $capacite): static
    {
        $this->capacite = $capacite;

        return $this;
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        $today = new \DateTimeImmutable('today');

        if ($this->dateDebut < $today) {
            $context->buildViolation('La date de debut ne peut pas etre dans le passe.')
                ->atPath('dateDebut')
                ->addViolation();
        }

        if ($this->dateFin < $today) {
            $context->buildViolation('La date de fin ne peut pas etre dans le passe.')
                ->atPath('dateFin')
                ->addViolation();
        }

        if ($this->dateFin < $this->dateDebut) {
            $context->buildViolation('La date de fin doit etre apres la date de debut.')
                ->atPath('dateFin')
                ->addViolation();
        }
    }
}
