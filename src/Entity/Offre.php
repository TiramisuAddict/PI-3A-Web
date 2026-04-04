<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\OffreRepository;

use App\Entity\Candidat;
use App\Repository\CandidatRepository;

#[ORM\Entity(repositoryClass: OffreRepository::class)]
#[ORM\Table(name: 'offre')]
class Offre
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

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $id_employer = null;

    public function getId_employer(): ?int
    {
        return $this->id_employer;
    }

    public function setId_employer(?int $id_employer): self
    {
        $this->id_employer = $id_employer;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $titre_poste = null;

    public function getTitre_poste(): ?string
    {
        return $this->titre_poste;
    }

    public function setTitre_poste(?string $titre_poste): self
    {
        $this->titre_poste = $titre_poste;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $type_contrat = null;

    public function getType_contrat(): ?string
    {
        return $this->type_contrat;
    }

    public function setType_contrat(?string $type_contrat): self
    {
        $this->type_contrat = $type_contrat;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $date_limite = null;

    public function getDate_limite(): ?\DateTimeInterface
    {
        return $this->date_limite;
    }

    public function setDate_limite(?\DateTimeInterface $date_limite): self
    {
        $this->date_limite = $date_limite;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $etat = null;

    public function getEtat(): ?string
    {
        return $this->etat;
    }

    public function setEtat(?string $etat): self
    {
        $this->etat = $etat;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 15, nullable: true)]
    private ?string $categorie = null;

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): self
    {
        $this->categorie = $categorie;
        return $this;
    }

    //=========================================================

    #[ORM\OneToMany(targetEntity: Candidat::class, mappedBy: 'offre')]
    private Collection $candidats;

    public function __construct()
    {
        $this->candidats = new ArrayCollection();
    }

    /**
     * @return Collection<int, Candidat>
     */
    public function getCandidats(): Collection
    {
        if (!$this->candidats instanceof Collection) {
            $this->candidats = new ArrayCollection();
        }
        return $this->candidats;
    }

    public function addCandidat(Candidat $candidat): self
    {
        if (!$this->getCandidats()->contains($candidat)) {
            $this->getCandidats()->add($candidat);
        }
        return $this;
    }

    public function removeCandidat(Candidat $candidat): self
    {
        $this->getCandidats()->removeElement($candidat);
        return $this;
    }

}
