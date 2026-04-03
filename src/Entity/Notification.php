<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\NotificationRepository;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_notification = null;

    public function getId_notification(): ?int
    {
        return $this->id_notification;
    }

    public function setId_notification(int $id_notification): self
    {
        $this->id_notification = $id_notification;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Employé::class, inversedBy: 'notifications')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id_employe')]
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

    #[ORM\Column(type: 'string', nullable: false)]
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
    private ?\DateTimeInterface $date_creation = null;

    public function getDate_creation(): ?\DateTimeInterface
    {
        return $this->date_creation;
    }

    public function setDate_creation(\DateTimeInterface $date_creation): self
    {
        $this->date_creation = $date_creation;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $is_read = null;

    public function is_read(): ?bool
    {
        return $this->is_read;
    }

    public function setIs_read(?bool $is_read): self
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
