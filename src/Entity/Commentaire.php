<?php

namespace App\Entity;

use App\Repository\CommentaireRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommentaireRepository::class)]
#[ORM\Table(name: 'commentaire')]
class Commentaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_commentaire = null;

    public function getIdCommentaire(): ?int
    {
        return $this->id_commentaire;
    }

    public function setIdCommentaire(int $id_commentaire): self
    {
        $this->id_commentaire = $id_commentaire;

        return $this;
    }

    #[ORM\Column(type: 'text', nullable: false)]
    #[Assert\NotBlank(message: 'Le commentaire ne peut pas être vide.')]
    #[Assert\Length(min: 3, minMessage: 'Le commentaire doit contenir au moins {{ limit }} caractères.')]
    private ?string $contenu = null;

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;

        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    #[Assert\NotNull(message: 'La date du commentaire est obligatoire.')]
    #[Assert\Type(\DateTimeInterface::class)]
    #[Assert\DateTime(message: 'La date du commentaire doit être valide.')]
    private ?\DateTimeInterface $date_commentaire = null;

    public function getDateCommentaire(): ?\DateTimeInterface
    {
        return $this->date_commentaire;
    }

    public function setDateCommentaire(\DateTimeInterface $date_commentaire): self
    {
        $this->date_commentaire = $date_commentaire;

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

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'commentaires')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id_post', nullable: false)]
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
}
