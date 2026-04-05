<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ParticipationRepository;

#[ORM\Entity(repositoryClass: ParticipationRepository::class)]
#[ORM\Table(name: 'participation')]
class Participation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_participation = null;

    public function getId_participation(): ?int
    {
        return $this->id_participation;
    }

    public function setId_participation(int $id_participation): self
    {
        $this->id_participation = $id_participation;
        return $this;
    }

    #[ORM\OneToOne(targetEntity: Employe::class, inversedBy: 'participation')]
    #[ORM\JoinColumn(name: 'utilisateur_id', referencedColumnName: 'id_employe', unique: true)]
    private ?Employe $employé = null;

    public function getEmployé(): ?Employe
    {
        return $this->employé;
    }

    public function setEmployé(?Employe $employé): self
    {
        $this->employé = $employé;
        return $this;
    }

    #[ORM\OneToOne(targetEntity: Post::class, inversedBy: 'participation')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id_post', unique: true)]
    private ?Post $post = null;

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function setPost(?Post $post): self
    {
        $this->post = $post;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $statut = null;

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $date_action = null;

    public function getDate_action(): ?\DateTimeInterface
    {
        return $this->date_action;
    }

    public function setDate_action(\DateTimeInterface $date_action): self
    {
        $this->date_action = $date_action;
        return $this;
    }

}
