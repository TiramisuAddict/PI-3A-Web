<?php

namespace App\Entity;

use App\Enum\StatutInscription;
use App\Repository\InscriptionFormationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InscriptionFormationRepository::class)]
#[ORM\Table(name: 'inscription_formation')]
class InscriptionFormation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_inscription')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Formation::class)]
    #[ORM\JoinColumn(name: 'id_formation', referencedColumnName: 'id_formation', nullable: false, onDelete: 'CASCADE')]
    private ?Formation $formation = null;

    #[ORM\Column(name: 'id_employe')]
    private int $employeeId;

    #[ORM\Column(length: 20)]
    private string $statut = 'EN_ATTENTE';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'La raison ne peut pas depasser {{ limit }} caracteres.')]
    private ?string $raison = null;

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

    public function getEmployeeId(): int
    {
        return $this->employeeId;
    }

    public function setEmployeeId(int $employeeId): static
    {
        $this->employeeId = $employeeId;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string|StatutInscription $statut): static
    {
        $this->statut = $statut instanceof StatutInscription ? $statut->value : $statut;

        return $this;
    }

    public function getRaison(): ?string
    {
        return $this->raison;
    }

    public function setRaison(?string $raison): static
    {
        $this->raison = $raison;

        return $this;
    }
}
