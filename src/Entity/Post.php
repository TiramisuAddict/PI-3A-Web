<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\PostRepository;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'post')]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_post = null;

    public function getId_post(): ?int
    {
        return $this->id_post;
    }

    public function setId_post(int $id_post): self
    {
        $this->id_post = $id_post;
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

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $type_post = null;

    public function getType_post(): ?int
    {
        return $this->type_post;
    }

    public function setType_post(int $type_post): self
    {
        $this->type_post = $type_post;
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

    #[ORM\ManyToOne(targetEntity: Employé::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(name: 'utilisateur_id', referencedColumnName: 'id_employe')]
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

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $active = null;

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): self
    {
        $this->active = $active;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $date_evenement = null;

    public function getDate_evenement(): ?\DateTimeInterface
    {
        return $this->date_evenement;
    }

    public function setDate_evenement(?\DateTimeInterface $date_evenement): self
    {
        $this->date_evenement = $date_evenement;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $date_fin_evenement = null;

    public function getDate_fin_evenement(): ?\DateTimeInterface
    {
        return $this->date_fin_evenement;
    }

    public function setDate_fin_evenement(?\DateTimeInterface $date_fin_evenement): self
    {
        $this->date_fin_evenement = $date_fin_evenement;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $lieu = null;

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): self
    {
        $this->lieu = $lieu;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacite_max = null;

    public function getCapacite_max(): ?int
    {
        return $this->capacite_max;
    }

    public function setCapacite_max(?int $capacite_max): self
    {
        $this->capacite_max = $capacite_max;
        return $this;
    }

    #[ORM\Column(type: 'decimal', nullable: true)]
    private ?float $latitude = null;

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): self
    {
        $this->latitude = $latitude;
        return $this;
    }

    #[ORM\Column(type: 'decimal', nullable: true)]
    private ?float $longitude = null;

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): self
    {
        $this->longitude = $longitude;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Commentaire::class, mappedBy: 'post')]
    private Collection $commentaires;

    /**
     * @return Collection<int, Commentaire>
     */
    public function getCommentaires(): Collection
    {
        if (!$this->commentaires instanceof Collection) {
            $this->commentaires = new ArrayCollection();
        }
        return $this->commentaires;
    }

    public function addCommentaire(Commentaire $commentaire): self
    {
        if (!$this->getCommentaires()->contains($commentaire)) {
            $this->getCommentaires()->add($commentaire);
        }
        return $this;
    }

    public function removeCommentaire(Commentaire $commentaire): self
    {
        $this->getCommentaires()->removeElement($commentaire);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: EventImage::class, mappedBy: 'post')]
    private Collection $eventImages;

    /**
     * @return Collection<int, EventImage>
     */
    public function getEventImages(): Collection
    {
        if (!$this->eventImages instanceof Collection) {
            $this->eventImages = new ArrayCollection();
        }
        return $this->eventImages;
    }

    public function addEventImage(EventImage $eventImage): self
    {
        if (!$this->getEventImages()->contains($eventImage)) {
            $this->getEventImages()->add($eventImage);
        }
        return $this;
    }

    public function removeEventImage(EventImage $eventImage): self
    {
        $this->getEventImages()->removeElement($eventImage);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'post')]
    private Collection $notifications;

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        if (!$this->notifications instanceof Collection) {
            $this->notifications = new ArrayCollection();
        }
        return $this->notifications;
    }

    public function addNotification(Notification $notification): self
    {
        if (!$this->getNotifications()->contains($notification)) {
            $this->getNotifications()->add($notification);
        }
        return $this;
    }

    public function removeNotification(Notification $notification): self
    {
        $this->getNotifications()->removeElement($notification);
        return $this;
    }

    #[ORM\OneToOne(targetEntity: Participation::class, mappedBy: 'post')]
    private ?Participation $participation = null;

    public function getParticipation(): ?Participation
    {
        return $this->participation;
    }

    public function setParticipation(?Participation $participation): self
    {
        $this->participation = $participation;
        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Employé::class, inversedBy: 'posts')]
    #[ORM\JoinTable(
        name: 'like_post',
        joinColumns: [
            new ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id_post')
        ],
        inverseJoinColumns: [
            new ORM\JoinColumn(name: 'utilisateur_id', referencedColumnName: 'id_employe')
        ]
    )]
    private Collection $employés;

    /**
     * @return Collection<int, Employé>
     */
    public function getEmployés(): Collection
    {
        if (!$this->employés instanceof Collection) {
            $this->employés = new ArrayCollection();
        }
        return $this->employés;
    }

    public function addEmployé(Employé $employé): self
    {
        if (!$this->getEmployés()->contains($employé)) {
            $this->getEmployés()->add($employé);
        }
        return $this;
    }

    public function removeEmployé(Employé $employé): self
    {
        $this->getEmployés()->removeElement($employé);
        return $this;
    }

}
