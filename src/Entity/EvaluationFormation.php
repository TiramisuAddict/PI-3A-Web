<?php

namespace App\Entity;

use App\Repository\EvaluationFormationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EvaluationFormationRepository::class)]
#[ORM\Table(name: 'evaluation_formation')]
#[ORM\HasLifecycleCallbacks]
class EvaluationFormation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_evaluation')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Formation::class)]
    #[ORM\JoinColumn(name: 'id_formation', referencedColumnName: 'id_formation', nullable: false, onDelete: 'CASCADE')]
    private ?Formation $formation = null;

    #[ORM\ManyToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe', nullable: false, onDelete: 'CASCADE')]
    private ?Employe $employe = null;

    #[ORM\Column(type: 'integer')]
    private int $note = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(name: 'date_evaluation', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateEvaluation = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormation(): ?Formation
    {
        return $this->formation;
    }

    public function setFormation(?Formation $formation): static
    {
        $this->formation = $formation;

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

    public function getNote(): int
    {
        return $this->note;
    }

    public function setNote(int $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function getDateEvaluation(): ?\DateTimeInterface
    {
        return $this->dateEvaluation;
    }

    #[ORM\PrePersist]
    public function initializeDateEvaluation(): void
    {
        if ($this->dateEvaluation === null) {
              $this->dateEvaluation = new \DateTime();
        }
    }
}
