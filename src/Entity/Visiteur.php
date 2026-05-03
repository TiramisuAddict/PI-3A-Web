<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

use App\Repository\VisiteurRepository;

#[ORM\Entity(repositoryClass: VisiteurRepository::class)]
#[ORM\Table(name: 'visiteur')]
class Visiteur implements PasswordAuthenticatedUserInterface
{
    public function __construct()
    {
        $this->candidats = new ArrayCollection();
    }

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

    #[ORM\Column(type: 'string', nullable: false)]
    private string $nom;

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private string $prenom;

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): self
    {
        $this->prenom = $prenom;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private string $e_mail;

    public function getEmail(): string
    {
        return $this->e_mail;
    }

    public function setEmail(string $e_mail): self
    {
        $this->e_mail = $e_mail;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private string $mot_de_passe;

    public function getMotdepasse(): string
    {
        return $this->mot_de_passe;
    }

    public function setMotdepasse(string $mot_de_passe): self
    {
        $this->mot_de_passe = $mot_de_passe;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->mot_de_passe;
    }

    public function eraseCredentials(): void
    {
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private int $telephone;

    public function getTelephone(): int
    {
        return $this->telephone;
    }

    public function setTelephone(int $telephone): self
    {
        $this->telephone = $telephone;
        return $this;
    }

    /** @var Collection<int, Candidat> */
    #[ORM\OneToMany(targetEntity: Candidat::class, mappedBy: 'visiteur')]
    private Collection $candidats;

    /**
     * @return Collection<int, Candidat>
     */
    public function getCandidats(): Collection
    {
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
