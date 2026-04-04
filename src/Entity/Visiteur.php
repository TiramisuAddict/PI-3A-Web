<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\VisiteurRepository;

#[ORM\Entity(repositoryClass: VisiteurRepository::class)]
#[ORM\Table(name: 'visiteur')]
class Visiteur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_visiteur = null;

    public function getId_visiteur(): ?int
    {
        return $this->id_visiteur;
    }

    public function setId_visiteur(int $id_visiteur): self
    {
        $this->id_visiteur = $id_visiteur;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30, nullable: false)]
    private ?string $nom = null;

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30, nullable: false)]
    private ?string $prenom = null;

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): self
    {
        $this->prenom = $prenom;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30, nullable: false)]
    private ?string $e_mail = null;

    public function getE_mail(): ?string
    {
        return $this->e_mail;
    }

    public function setE_mail(string $e_mail): self
    {
        $this->e_mail = $e_mail;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 100, nullable: false)]
    private ?string $mot_de_passe = null;

    public function getMot_de_passe(): ?string
    {
        return $this->mot_de_passe;
    }

    public function setMot_de_passe(string $mot_de_passe): self
    {
        $this->mot_de_passe = $mot_de_passe;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $telephone = null;

    public function getTelephone(): ?int
    {
        return $this->telephone;
    }

    public function setTelephone(int $telephone): self
    {
        $this->telephone = $telephone;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Candidat::class, mappedBy: 'visiteur')]
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
