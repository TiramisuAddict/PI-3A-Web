<?php

namespace App\Entity;

use App\Repository\EventImageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EventImageRepository::class)]
#[ORM\Table(name: 'event_image')]
class EventImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_image = null;

    public function getIdImage(): ?int
    {
        return $this->id_image;
    }

    public function setIdImage(int $id_image): self
    {
        $this->id_image = $id_image;

        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'eventImages')]
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

    #[ORM\Column(type: 'string', nullable: false)]
    #[Assert\NotBlank(message: 'Le chemin de l’image est obligatoire.')]
    #[Assert\Length(min: 3, max: 512, minMessage: 'Le chemin doit contenir au moins {{ limit }} caractères.')]
    private ?string $image_path = null;

    public function getImagePath(): ?string
    {
        return $this->image_path;
    }

    public function setImagePath(string $image_path): self
    {
        $this->image_path = $image_path;

        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Type(type: 'integer', message: 'L’ordre doit être un entier.')]
    #[Assert\PositiveOrZero(message: 'L’ordre doit être positif ou zéro.')]
    private ?int $ordre = null;

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(?int $ordre): self
    {
        $this->ordre = $ordre;

        return $this;
    }
}
