<?php

namespace App\Entity;

use App\Repository\LikePostRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LikePostRepository::class)]
#[ORM\Table(name: 'like_post')]
class LikePost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_like = null;

    public function getIdLike(): ?int
    {
        return $this->id_like;
    }

    public function setIdLike(int $id_like): self
    {
        $this->id_like = $id_like;

        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    #[Assert\NotNull(message: 'L’identifiant utilisateur est obligatoire.')]
    #[Assert\Type(type: 'integer', message: 'L’identifiant utilisateur doit être un entier.')]
    private ?int $utilisateur_id = null;

    public function getUtilisateurId(): ?int
    {
        return $this->utilisateur_id;
    }

    public function setUtilisateurId(int $utilisateur_id): self
    {
        $this->utilisateur_id = $utilisateur_id;

        return $this;
    }

    #[ORM\OneToOne(targetEntity: Post::class, inversedBy: 'likePost')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id_post', unique: true, nullable: false)]
    #[Assert\NotNull(message: 'Le post associé est obligatoire.')]
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

    #[ORM\Column(type: 'datetime', nullable: false)]
    #[Assert\NotNull(message: 'La date du like est obligatoire.')]
    #[Assert\Type(\DateTimeInterface::class)]
    #[Assert\DateTime(message: 'La date du like doit être valide.')]
    private ?\DateTimeInterface $date_like = null;

    public function getDateLike(): ?\DateTimeInterface
    {
        return $this->date_like;
    }

    public function setDateLike(\DateTimeInterface $date_like): self
    {
        $this->date_like = $date_like;

        return $this;
    }
}
