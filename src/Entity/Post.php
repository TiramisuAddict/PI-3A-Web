<?php

namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'post')]
#[Assert\Callback('validateEvenementFields')]
class Post
{
    public function __construct()
    {
        $this->eventImages = new ArrayCollection();
        $this->commentaires = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->date_creation = new \DateTimeImmutable();
        $this->coordinates = new Coordinates();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_post = null;

    public function getIdPost(): ?int
    {
        return $this->id_post;
    }

    public function setIdPost(int $id_post): self
    {
        $this->id_post = $id_post;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(min: 3, max: 255, minMessage: 'Le titre doit contenir au moins {{ limit }} caractères.')]
    private string $titre = '';

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: false)]
    #[Assert\NotBlank(message: 'Le contenu est obligatoire.')]
    #[Assert\Length(min: 3, minMessage: 'Le contenu doit contenir au moins {{ limit }} caractères.')]
    private string $contenu = '';

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): self
    {
        $this->contenu = $contenu;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    #[Assert\NotNull(message: 'Le type de publication est obligatoire.')]
    #[Assert\Type(type: 'integer', message: 'Le type doit être un nombre entier.')]
    private int $type_post = 1;

    public function getTypePost(): int
    {
        return $this->type_post;
    }

    public function setTypePost(int $type_post): self
    {
        $this->type_post = $type_post;
        return $this;
    }

    #[ORM\Column(type: 'datetime_immutable', nullable: false)]
    #[Assert\NotNull(message: 'La date de création est obligatoire.')]
    #[Assert\Type(\DateTimeInterface::class, message: 'La date de création doit être une date/heure valide.')]
    private \DateTimeImmutable $date_creation;

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->date_creation;
    }

    public function setDateCreation(\DateTimeInterface $date_creation): self
    {
        $this->date_creation = \DateTimeImmutable::createFromInterface($date_creation);
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    #[Assert\NotNull(message: 'L’identifiant utilisateur est obligatoire.')]
    #[Assert\Type(type: 'integer', message: 'L’identifiant utilisateur doit être un entier.')]
    private int $utilisateur_id = 0;

    public function getUtilisateurId(): int
    {
        return $this->utilisateur_id;
    }

    public function setUtilisateurId(int $utilisateur_id): self
    {
        $this->utilisateur_id = $utilisateur_id;
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

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Assert\Type(\DateTimeInterface::class, message: 'La date de début d’événement doit être une date valide.')]
    private ?\DateTimeImmutable $date_evenement = null;

    public function getDateEvenement(): ?\DateTimeImmutable
    {
        return $this->date_evenement;
    }

    public function setDateEvenement(?\DateTimeInterface $date_evenement): self
    {
        $this->date_evenement = $date_evenement !== null
            ? \DateTimeImmutable::createFromInterface($date_evenement)
            : null;
        return $this;
    }

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Assert\Type(\DateTimeInterface::class, message: 'La date de fin d’événement doit être une date valide.')]
    private ?\DateTimeImmutable $date_fin_evenement = null;

    public function getDateFinEvenement(): ?\DateTimeImmutable
    {
        return $this->date_fin_evenement;
    }

    public function setDateFinEvenement(?\DateTimeInterface $date_fin_evenement): self
    {
        $this->date_fin_evenement = $date_fin_evenement !== null
            ? \DateTimeImmutable::createFromInterface($date_fin_evenement)
            : null;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    #[Assert\Length(max: 255)]
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
    #[Assert\Positive(message: 'La capacité maximale doit être un entier strictement positif.')]
    private ?int $capacite_max = null;

    public function getCapaciteMax(): ?int
    {
        return $this->capacite_max;
    }

    public function setCapaciteMax(?int $capacite_max): self
    {
        $this->capacite_max = $capacite_max;
        return $this;
    }

    #[ORM\Embedded(class: Coordinates::class, columnPrefix: false)]
    #[Assert\Valid]
    private Coordinates $coordinates;

    public function getLatitude(): ?string
    {
        return $this->getCoordinates()->getLatitude();
    }

    public function setLatitude(float|string|null $latitude): self
    {
        $this->getCoordinates()->setLatitude($latitude);

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->getCoordinates()->getLongitude();
    }

    public function setLongitude(float|string|null $longitude): self
    {
        $this->getCoordinates()->setLongitude($longitude);

        return $this;
    }

    public function getCoordinates(): Coordinates
    {
        if (!isset($this->coordinates)) {
            $this->coordinates = new Coordinates();
        }

        return $this->coordinates;
    }

    public function setCoordinates(?Coordinates $coordinates): self
    {
        $this->coordinates = $coordinates ?? new Coordinates();

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

    #[ORM\OneToMany(targetEntity: EventImage::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

        if ($eventImage->getPost() !== $this) {
            $eventImage->setPost($this);
        }

        return $this;
    }

    public function removeEventImage(EventImage $eventImage): self
    {
        if ($this->getEventImages()->removeElement($eventImage) && $eventImage->getPost() === $this) {
            $eventImage->setPost(null);
        }

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

    /**
     * Validates event-specific fields for Événement posts only.
     * Required fields: dateDebut, dateFin, lieu, capaciteMax
     * Constraints:
     *   - dateDebut must be strictly before dateFin
     *   - dateDebut must be at least 7 days after today
     *   - capaciteMax must be >= 15
     */
    public function validateEvenementFields(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        // Only validate event-specific fields when typePost = 2 (Événement)
        if ($this->type_post !== 2) {
            return;
        }

        // Validation 1: dateDebut (date_evenement) is required
        if ($this->date_evenement === null) {
            $context->buildViolation('La date de début est obligatoire pour un événement.')
                ->atPath('date_evenement')
                ->addViolation();
        }

        // Validation 2: dateFin (date_fin_evenement) is required
        if ($this->date_fin_evenement === null) {
            $context->buildViolation('La date de fin est obligatoire pour un événement.')
                ->atPath('date_fin_evenement')
                ->addViolation();
        }

        // Validation 3: lieu is required (not null or empty)
        if ($this->lieu === null || trim($this->lieu) === '') {
            $context->buildViolation('Le lieu est obligatoire pour un événement.')
                ->atPath('lieu')
                ->addViolation();
        }

        // Validation 4: capaciteMax is required
        if ($this->capacite_max === null) {
            $context->buildViolation('La capacité maximale est obligatoire pour un événement.')
                ->atPath('capacite_max')
                ->addViolation();
        }

        // Validation 5: capaciteMax must be >= 15
        if ($this->capacite_max !== null && $this->capacite_max < 15) {
            $context->buildViolation('La capacité maximale doit être d\'au moins 15 places pour un événement.')
                ->atPath('capacite_max')
                ->addViolation();
        }

        // Validation 6: dateDebut must be strictly before dateFin
        if ($this->date_evenement !== null && $this->date_fin_evenement !== null) {
            if ($this->date_evenement >= $this->date_fin_evenement) {
                $context->buildViolation('La date de début doit être strictement antérieure à la date de fin.')
                    ->atPath('date_evenement')
                    ->addViolation();
            }
        }

        // Validation 7: dateDebut must be at least 7 days after today
        if ($this->date_evenement !== null) {
            $today = new \DateTimeImmutable('today');
            $minDate = $today->modify('+7 days');
            
            if ($this->date_evenement < $minDate) {
                $context->buildViolation('La date de début doit être au moins 7 jours après la date d\'aujourd\'hui.')
                    ->atPath('date_evenement')
                    ->addViolation();
            }
        }
    }

}
