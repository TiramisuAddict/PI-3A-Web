<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\CompetenceEmployeRepository;

#[ORM\Entity(repositoryClass: CompetenceEmployeRepository::class)]
#[ORM\Table(name: 'competence_employe')]
class CompetenceEmploye
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $skills = null;

    public function getSkills(): ?string
    {
        return $this->skills;
    }

    public function setSkills(?string $skills): self
    {
        $this->skills = $skills;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $formations = null;

    public function getFormations(): ?string
    {
        return $this->formations;
    }

    public function setFormations(?string $formations): self
    {
        $this->formations = $formations;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $experience = null;

    public function getExperience(): ?string
    {
        return $this->experience;
    }

    public function setExperience(?string $experience): self
    {
        $this->experience = $experience;
        return $this;
    }

    #[ORM\OneToOne(targetEntity: Employe::class, inversedBy: 'competenceEmploye')]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe', unique: true)]
    private ?Employe $employe = null;

    public function getEmploye(): ?Employe
    {
        return $this->employe;
    }

    public function setEmploye(?Employe $employe): self
    {
        $this->employe = $employe;
        return $this;
    }

}
