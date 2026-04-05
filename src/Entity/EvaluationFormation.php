<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\EvaluationFormationRepository;

#[ORM\Entity(repositoryClass: EvaluationFormationRepository::class)]
#[ORM\Table(name: 'evaluation_formation')]
class EvaluationFormation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_evaluation = null;

    public function getId_evaluation(): ?int
    {
        return $this->id_evaluation;
    }

    public function setId_evaluation(int $id_evaluation): self
    {
        $this->id_evaluation = $id_evaluation;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $id_formation = null;

    public function getId_formation(): ?int
    {
        return $this->id_formation;
    }

    public function setId_formation(int $id_formation): self
    {
        $this->id_formation = $id_formation;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $id_employe = null;

    public function getId_employe(): ?int
    {
        return $this->id_employe;
    }

    public function setId_employe(int $id_employe): self
    {
        $this->id_employe = $id_employe;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $note = null;

    public function getNote(): ?int
    {
        return $this->note;
    }

    public function setNote(int $note): self
    {
        $this->note = $note;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): self
    {
        $this->commentaire = $commentaire;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $date_evaluation = null;

    public function getDate_evaluation(): ?\DateTimeInterface
    {
        return $this->date_evaluation;
    }

    public function setDate_evaluation(?\DateTimeInterface $date_evaluation): self
    {
        $this->date_evaluation = $date_evaluation;
        return $this;
    }

}