php -S 127.0.0.1:8000 -t public<?php

namespace App\Entity;

use App\Repository\CompetenceEmployeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompetenceEmployeRepository::class)]
#[ORM\Table(name: 'competence_employe')]
class CompetenceEmploye
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $skills = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $formations = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $experience = null;

    #[ORM\OneToOne(targetEntity: Employe::class)]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe', nullable: false, onDelete: 'CASCADE')]
    private ?Employe $employe = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSkills(): ?string
    {
        return $this->skills;
    }

    public function setSkills(?string $skills): static
    {
        $this->skills = $skills;

        return $this;
    }

    public function getFormations(): ?string
    {
        return $this->formations;
    }

    public function setFormations(?string $formations): static
    {
        $this->formations = $formations;

        return $this;
    }

    public function getExperience(): ?string
    {
        return $this->experience;
    }

    public function setExperience(?string $experience): static
    {
        $this->experience = $experience;

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
}
