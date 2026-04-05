<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_notification = null;

    public function getIdNotification(): ?int
    {
        return $this->id_notification;
    }

    public function setIdNotification(int $id_notification): self
    {
        $this->id_notification = $id_notification;

        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    #[Assert\NotNull(message: 'L’identifiant utilisateur est obligatoire.')]
    #[Assert\Type(type: 'integer', message: 'L’identifiant utilisateur doit être un entier.')]
    private ?int $user_id = null;

    public function getUserId(): ?int
    {
        return $this->user_id;
    }

    public function setUserId(int $user_id): self
    {
        $this->user_id = $user_id;

        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(min: 3, max: 255, minMessage: 'Le titre doit contenir au moins {{ limit }} caractères.')]
    private ?string $titre = null;

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;

        return $this;
    }

    #[ORM\Column(type: 'text', nullable: false)]
    #[Assert\NotBlank(message: 'Le message est obligatoire.')]
    #[Assert\Length(min: 3, minMessage: 'Le message doit contenir au moins {{ limit }} caractères.')]
    private ?string $message = null;

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    #[Assert\NotNull(message: 'La date de création est obligatoire.')]
    #[Assert\Type(\DateTimeInterface::class)]
    #[Assert\DateTime(message: 'La date de création doit être valide.')]
    private ?\DateTimeInterface $date_creation = null;

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->date_creation;
    }

    public function setDateCreation(\DateTimeInterface $date_creation): self
    {
        $this->date_creation = $date_creation;

        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Assert\Type(type: 'bool', message: 'La valeur « lu » doit être vrai ou faux.')]
    private ?bool $is_read = null;

    public function isRead(): ?bool
    {
        return $this->is_read;
    }

    public function getIsRead(): ?bool
    {
        return $this->is_read;
    }

    public function setIsRead(?bool $is_read): self
    {
        $this->is_read = $is_read;

        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'notifications')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id_post')]
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
