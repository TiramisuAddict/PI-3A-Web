<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use App\Repository\ProjetRepository;

#[ORM\Entity(repositoryClass: ProjetRepository::class)]
#[ORM\Table(name: 'projet')]
class Projet
{
    public const STATUT_PLANIFIE = 'PLANIFIE';
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_EN_ATTENTE = 'EN_ATTENTE';
    public const STATUT_TERMINE = 'TERMINE';
    public const STATUT_ANNULE = 'ANNULE';

    public const PRIORITE_BASSE = 'BASSE';
    public const PRIORITE_MOYENNE = 'MOYENNE';
    public const PRIORITE_HAUTE = 'HAUTE';

    public const STATUT_VALUES = [
        self::STATUT_PLANIFIE,
        self::STATUT_EN_COURS,
        self::STATUT_EN_ATTENTE,
        self::STATUT_TERMINE,
        self::STATUT_ANNULE,
    ];

    public const PRIORITE_VALUES = [
        self::PRIORITE_BASSE,
        self::PRIORITE_MOYENNE,
        self::PRIORITE_HAUTE,
    ];

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

    #[ORM\ManyToOne(targetEntity: Employé::class, inversedBy: 'projetsResponsables')]
    #[ORM\JoinColumn(name: 'responsable_id', referencedColumnName: 'id_employe', nullable: false)]
    #[Assert\NotNull(message: 'Veuillez choisir un chef projet.')]
    private ?Employé $responsable = null;

    public function getResponsable(): ?Employé
    {
        return $this->responsable;
    }

    public function setResponsable(?Employé $responsable): self
    {
        $this->responsable = $responsable;

        return $this;
    }

    public function getEmploye(): ?Employé
    {
        return $this->responsable;
    }

    public function setEmploye(?Employé $employe): self
    {
        return $this->setResponsable($employe);
    }

    #[ORM\Column(type: 'string', nullable: false)]
    #[Assert\NotBlank(message: 'Le nom du projet est obligatoire.', normalizer: 'trim')]
    #[Assert\Length(
        min: 3,
        max: 100,
        minMessage: 'Le nom doit contenir au moins {{ limit }} caracteres.',
        maxMessage: 'Le nom ne peut pas depasser {{ limit }} caracteres.',
        normalizer: 'trim'
    )]
    private ?string $nom = null;

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = trim(preg_replace('/\s+/', ' ', $nom) ?? '');
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: false)]
    #[Assert\NotBlank(message: 'La description est obligatoire.', normalizer: 'trim')]
    #[Assert\Length(
        min: 10,
        max: 1000,
        minMessage: 'La description doit contenir au moins {{ limit }} caracteres.',
        maxMessage: 'La description ne peut pas depasser {{ limit }} caracteres.',
        normalizer: 'trim'
    )]
    private ?string $description = null;

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = trim($description);
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    #[Assert\NotNull(message: 'La date de debut est obligatoire.')]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date de debut doit etre une date valide.')]
    private ?\DateTimeInterface $date_debut = null;

    public function getDate_debut(): ?\DateTimeInterface
    {
        return $this->date_debut;
    }

    public function setDate_debut(?\DateTimeInterface $date_debut): self
    {
        $this->date_debut = $date_debut;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    #[Assert\NotNull(message: 'La date de fin prevue est obligatoire.')]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date de fin prevue doit etre une date valide.')]
    private ?\DateTimeInterface $date_fin_prevue = null;

    public function getDate_fin_prevue(): ?\DateTimeInterface
    {
        return $this->date_fin_prevue;
    }

    public function setDate_fin_prevue(?\DateTimeInterface $date_fin_prevue): self
    {
        $this->date_fin_prevue = $date_fin_prevue;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date de fin reelle doit etre une date valide.')]
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
    #[Assert\NotBlank(message: 'Le statut est obligatoire.')]
    #[Assert\Choice(choices: self::STATUT_VALUES, message: 'Le statut selectionne est invalide.')]
    private ?string $statut = null;

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    #[Assert\NotBlank(message: 'La priorite est obligatoire.')]
    #[Assert\Choice(choices: self::PRIORITE_VALUES, message: 'La priorite selectionnee est invalide.')]
    private ?string $priorite = null;

    #[Assert\Callback]
    public function validateBusinessRules(ExecutionContextInterface $context): void
    {
        if ($this->date_debut instanceof \DateTimeInterface && $this->date_fin_prevue instanceof \DateTimeInterface && $this->date_fin_prevue < $this->date_debut) {
            $context
                ->buildViolation('La date de fin prevue doit etre superieure ou egale a la date de debut.')
                ->atPath('date_fin_prevue')
                ->addViolation();
        }

        if ($this->date_debut instanceof \DateTimeInterface && $this->date_fin_reelle instanceof \DateTimeInterface && $this->date_fin_reelle < $this->date_debut) {
            $context
                ->buildViolation('La date de fin reelle doit etre superieure ou egale a la date de debut.')
                ->atPath('date_fin_reelle')
                ->addViolation();
        }

        if ($this->statut === self::STATUT_TERMINE && !$this->date_fin_reelle instanceof \DateTimeInterface) {
            $context
                ->buildViolation('La date de fin reelle est requise quand le statut est TERMINE.')
                ->atPath('date_fin_reelle')
                ->addViolation();
        }

        if ($this->date_fin_reelle instanceof \DateTimeInterface && !in_array($this->statut, [self::STATUT_TERMINE, self::STATUT_ANNULE], true)) {
            $context
                ->buildViolation('La date de fin reelle ne peut etre renseignee que pour un projet TERMINE ou ANNULE.')
                ->atPath('date_fin_reelle')
                ->addViolation();
        }
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
            $tache->setProjet($this);
        }

        return $this;
    }

    public function removeTache(Tache $tache): self
    {
        if ($this->getTaches()->removeElement($tache) && $tache->getProjet() === $this) {
            $tache->setProjet(null);
        }

        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Employé::class, inversedBy: 'projetsEquipe')]
    #[ORM\JoinTable(
        name: 'equipe_projet',
        joinColumns: [
            new ORM\JoinColumn(name: 'id_projet', referencedColumnName: 'id_projet')
        ],
        inverseJoinColumns: [
            new ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe')
        ]
    )]
    private Collection $membresEquipe;

    public function __construct()
    {
        $this->taches = new ArrayCollection();
        $this->membresEquipe = new ArrayCollection();
    }

    /**
     * @return Collection<int, Employé>
     */
    public function getMembresEquipe(): Collection
    {
        if (!$this->membresEquipe instanceof Collection) {
            $this->membresEquipe = new ArrayCollection();
        }

        return $this->membresEquipe;
    }

    public function addMembreEquipe(Employé $employe): self
    {
        if (!$this->getMembresEquipe()->contains($employe)) {
            $this->getMembresEquipe()->add($employe);
            $employe->addProjetEquipe($this);
        }

        return $this;
    }

    public function removeMembreEquipe(Employé $employe): self
    {
        if ($this->getMembresEquipe()->removeElement($employe)) {
            $employe->removeProjetEquipe($this);
        }

        return $this;
    }

    /**
     * @param iterable<Employé> $membresEquipe
     */
    public function setMembresEquipe(iterable $membresEquipe): self
    {
        foreach ($this->getMembresEquipe()->toArray() as $membreActuel) {
            $this->removeMembreEquipe($membreActuel);
        }

        foreach ($membresEquipe as $membre) {
            $this->addMembreEquipe($membre);
        }

        return $this;
    }

    public function addMembresEquipe(Employé $employe): self
    {
        return $this->addMembreEquipe($employe);
    }

    public function removeMembresEquipe(Employé $employe): self
    {
        return $this->removeMembreEquipe($employe);
    }

    /**
     * @return Collection<int, Employé>
     */
    public function getEmployes(): Collection
    {
        return $this->getMembresEquipe();
    }

    public function addEmploye(Employé $employe): self
    {
        return $this->addMembreEquipe($employe);
    }

    public function removeEmploye(Employé $employe): self
    {
        return $this->removeMembreEquipe($employe);
    }

    public function getIdProjet(): ?int
    {
        return $this->id_projet;
    }

    public function getDateDebut(): ?\DateTime
    {
        return $this->date_debut;
    }

    public function setDateDebut(?\DateTimeInterface $date_debut): static
    {
        $this->date_debut = $date_debut;

        return $this;
    }

    public function getDateFinPrevue(): ?\DateTime
    {
        return $this->date_fin_prevue;
    }

    public function setDateFinPrevue(?\DateTimeInterface $date_fin_prevue): static
    {
        $this->date_fin_prevue = $date_fin_prevue;

        return $this;
    }

    public function getDateFinReelle(): ?\DateTime
    {
        return $this->date_fin_reelle;
    }

    public function setDateFinReelle(?\DateTimeInterface $date_fin_reelle): static
    {
        $this->date_fin_reelle = $date_fin_reelle;

        return $this;
    }

    public function addTach(Tache $tach): static
    {
        return $this->addTache($tach);
    }

    public function removeTach(Tache $tach): static
    {
        return $this->removeTache($tach);
    }

    public function addEmploy(Employé $employ): static
    {
        return $this->addEmploye($employ);
    }

    public function removeEmploy(Employé $employ): static
    {
        return $this->removeEmploye($employ);
    }

}
