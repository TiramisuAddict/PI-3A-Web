<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\CompétenceEmployéRepository;

#[ORM\Entity(repositoryClass: CompétenceEmployéRepository::class)]
#[ORM\Table(name: 'compétence_employé')]
class CompétenceEmployé
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

    #[ORM\OneToOne(targetEntity: Employé::class, inversedBy: 'compétenceEmployé')]
    #[ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe', unique: true)]
    private ?Employé $employé = null;

    public function getEmployé(): ?Employé
    {
        return $this->employé;
    }

    public function setEmployé(?Employé $employé): self
    {
        $this->employé = $employé;
        return $this;
    }

}
