<?php

namespace App\Entity;

use App\Repository\CommentaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommentaireRepository::class)]
#[ORM\Table(name: 'commentaire')]
class Commentaire
{
    public function __construct()
    {
        $this->replies = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_commentaire = null;

    #[ORM\Column(type: 'text', nullable: false)]
    #[Assert\NotBlank(message: 'Le commentaire ne peut pas etre vide.')]
    #[Assert\Length(min: 3, minMessage: 'Le commentaire doit contenir au moins {{ limit }} caracteres.')]
    private ?string $contenu = null;

    #[ORM\Column(type: 'datetime', nullable: false)]
    #[Assert\NotNull(message: 'La date du commentaire est obligatoire.')]
    #[Assert\Type(\DateTimeInterface::class)]
    #[Assert\DateTime(message: 'La date du commentaire doit etre valide.')]
    private ?\DateTimeInterface $date_commentaire = null;

    #[ORM\Column(type: 'integer', nullable: false)]
    #[Assert\NotNull(message: 'L identifiant utilisateur est obligatoire.')]
    #[Assert\Type(type: 'integer', message: 'L identifiant utilisateur doit etre un entier.')]
    private ?int $utilisateur_id = null;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'commentaires')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id_post', nullable: false)]
    #[Assert\NotNull(message: 'Le post associe est obligatoire.')]
    private ?Post $post = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $edited_at = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id_commentaire', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent', orphanRemoval: true)]
    #[ORM\OrderBy(['date_commentaire' => 'ASC'])]
    private Collection $replies;

    public function getIdCommentaire(): ?int
    {
        return $this->id_commentaire;
    }

    public function setIdCommentaire(int $id_commentaire): self
    {
        $this->id_commentaire = $id_commentaire;

        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;

        return $this;
    }

    public function getDateCommentaire(): ?\DateTimeInterface
    {
        return $this->date_commentaire;
    }

    public function setDateCommentaire(\DateTimeInterface $date_commentaire): self
    {
        $this->date_commentaire = $date_commentaire;

        return $this;
    }

    public function getUtilisateurId(): ?int
    {
        return $this->utilisateur_id;
    }

    public function setUtilisateurId(int $utilisateur_id): self
    {
        $this->utilisateur_id = $utilisateur_id;

        return $this;
    }

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function setPost(?Post $post): self
    {
        $this->post = $post;

        return $this;
    }

    public function getEditedAt(): ?\DateTimeInterface
    {
        return $this->edited_at;
    }

    public function setEditedAt(?\DateTimeInterface $edited_at): self
    {
        $this->edited_at = $edited_at;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function addReply(self $reply): self
    {
        if (!$this->replies->contains($reply)) {
            $this->replies->add($reply);
            $reply->setParent($this);
        }

        return $this;
    }

    public function removeReply(self $reply): self
    {
        if ($this->replies->removeElement($reply) && $reply->getParent() === $this) {
            $reply->setParent(null);
        }

        return $this;
    }
}
