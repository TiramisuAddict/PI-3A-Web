<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use App\Repository\TacheRepository;

#[ORM\Entity(repositoryClass: TacheRepository::class)]
#[ORM\Table(name: 'tache')]
class Tache
{
    public const STATUT_A_FAIRE = 'A_FAIRE';
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_BLOQUEE = 'BLOCQUEE';
    public const STATUT_TERMINEE = 'TERMINEE';

    public const PRIORITE_BASSE = 'BASSE';
    public const PRIORITE_MOYENNE = 'MOYENNE';
    public const PRIORITE_HAUTE = 'HAUTE';

    public const STATUT_VALUES = [
        self::STATUT_A_FAIRE,
        self::STATUT_EN_COURS,
        self::STATUT_BLOQUEE,
        self::STATUT_TERMINEE,
    ];

    public const PRIORITE_VALUES = [
        self::PRIORITE_BASSE,
        self::PRIORITE_MOYENNE,
        self::PRIORITE_HAUTE,
    ];

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
    #[Assert\NotNull(message: 'Le projet est obligatoire pour cette tache.')]
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
    #[Assert\NotNull(message: 'Veuillez choisir un employe.')] 
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
    #[Assert\NotBlank(message: 'Le titre est obligatoire.', normalizer: 'trim')]
    #[Assert\Length(min: 3, max: 150, minMessage: 'Le titre doit contenir au moins {{ limit }} caracteres.', maxMessage: 'Le titre ne peut pas depasser {{ limit }} caracteres.', normalizer: 'trim')]
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
    #[Assert\NotBlank(message: 'La description est obligatoire.', normalizer: 'trim')]
    #[Assert\Length(min: 10, max: 1000, minMessage: 'La description doit contenir au moins {{ limit }} caracteres.', maxMessage: 'La description ne peut pas depasser {{ limit }} caracteres.', normalizer: 'trim')]
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
    #[Assert\NotBlank(message: 'Le statut est obligatoire.')]
    #[Assert\Choice(choices: self::STATUT_VALUES, message: 'Le statut selectionne est invalide.')]
    private ?string $statut_tache = null;

    public function getStatut_tache(): ?string
    {
        return $this->statut_tache;
    }

    public function setStatut_tache(?string $statut_tache): self
    {
        $this->statut_tache = $statut_tache;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    #[Assert\NotBlank(message: 'La priorite est obligatoire.')]
    #[Assert\Choice(choices: self::PRIORITE_VALUES, message: 'La priorite selectionnee est invalide.')]
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

    #[ORM\Column(type: 'date', nullable: false)]
    #[Assert\NotNull(message: 'La date de debut est obligatoire.')]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date de debut doit etre une date valide.')]
    #[Assert\GreaterThanOrEqual('today', message: 'La date de debut ne peut pas etre dans le passe.')]
    private ?\DateTimeInterface $date_deb = null;

    public function getDate_deb(): ?\DateTimeInterface
    {
        return $this->date_deb;
    }

    public function setDate_deb(?\DateTimeInterface $date_deb): self
    {
        $this->date_deb = $date_deb instanceof \DateTimeImmutable
            ? \DateTime::createFromImmutable($date_deb)
            : $date_deb;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    #[Assert\NotNull(message: 'La date limite est obligatoire.')]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date limite doit etre une date valide.')]
    private ?\DateTimeInterface $date_limite = null;

    public function getDate_limite(): ?\DateTimeInterface
    {
        return $this->date_limite;
    }

    public function setDate_limite(?\DateTimeInterface $date_limite): self
    {
        $this->date_limite = $date_limite instanceof \DateTimeImmutable
            ? \DateTime::createFromImmutable($date_limite)
            : $date_limite;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'La progression doit etre comprise entre {{ min }} et {{ max }}.')]
    private ?int $progression = null;

    #[Assert\Callback]
    public function validateAssignment(ExecutionContextInterface $context): void
    {
        if (!$this->projet instanceof Projet || !$this->employé instanceof Employé) {
            return;
        }

        if (!$this->projet->getMembresEquipe()->contains($this->employé)) {
            $context
                ->buildViolation('L\'employe assigne doit appartenir a l\'equipe du projet.')
                ->atPath('employe')
                ->addViolation();
        }

        if ($this->date_deb instanceof \DateTimeInterface && $this->date_limite instanceof \DateTimeInterface && $this->date_deb > $this->date_limite) {
            $context
                ->buildViolation('La date limite doit etre superieure ou egale a la date de debut.')
                ->atPath('date_limite')
                ->addViolation();
        }
    }

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

    public function setStatutTache(?string $statut_tache): static
    {
        $this->statut_tache = $statut_tache;

        return $this;
    }

    public function getDateDeb(): ?\DateTimeInterface
    {
        return $this->date_deb;
    }

    public function setDateDeb(?\DateTimeInterface $date_deb): static
    {
        return $this->setDate_deb($date_deb);
    }

    public function getDateLimite(): ?\DateTimeInterface
    {
        return $this->date_limite;
    }

    public function setDateLimite(?\DateTimeInterface $date_limite): static
    {
        return $this->setDate_limite($date_limite);
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
