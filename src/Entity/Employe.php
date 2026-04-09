<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\EmployeRepository;

#[ORM\Entity(repositoryClass: EmployeRepository::class)]
#[ORM\Table(name: 'employe')]
class Employe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_employe = null;

    public function getId_employe(): ?int
    {
        return $this->id_employe;
    }

    public function setId_employe(int $id_employe): self
    {
        $this->id_employe = $id_employe;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
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

    #[ORM\Column(type: 'string', nullable: false)]
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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $e_mail = null;

    public function getEmail(): ?string
    {
        return $this->e_mail;
    }

    public function setEmail(string $e_mail): self
    {
        $this->e_mail = $e_mail;
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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $poste = null;

    public function getPoste(): ?string
    {
        return $this->poste;
    }

    public function setPoste(string $poste): self
    {
        $this->poste = $poste;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $role = null;

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $date_embauche = null;

    public function getDateEmbauche(): ?\DateTimeInterface
    {
        return $this->date_embauche;
    }

    public function setDateEmbauche(?\DateTimeInterface $date_embauche): self
    {
        $this->date_embauche = $date_embauche;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $image_profil = null;

    public function getImage_profil(): ?string
    {
        return $this->image_profil;
    }

    public function setImage_profil(?string $image_profil): self
    {
        $this->image_profil = $image_profil;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Entreprise::class, inversedBy: 'employes')]
    #[ORM\JoinColumn(name: 'id_entreprise', referencedColumnName: 'id_entreprise')]
    private ?Entreprise $entreprise = null;

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): self
    {
        $this->entreprise = $entreprise;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cv_data = null;

    public function getCv_data(): ?string
    {
        return $this->cv_data;
    }

    public function setCv_data(?string $cv_data): self
    {
        $this->cv_data = $cv_data;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $cv_nom = null;

    public function getCv_nom(): ?string
    {
        return $this->cv_nom;
    }

    public function setCv_nom(?string $cv_nom): self
    {
        $this->cv_nom = $cv_nom;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Compte::class, mappedBy: 'employe')]
    private Collection $comptes;

    /**
     * @return Collection<int, Compte>
     */
    public function getComptes(): Collection
    {
        if (!$this->comptes instanceof Collection) {
            $this->comptes = new ArrayCollection();
        }
        return $this->comptes;
    }

    public function addCompte(Compte $compte): self
    {
        if (!$this->getComptes()->contains($compte)) {
            $this->getComptes()->add($compte);
        }
        return $this;
    }

    public function removeCompte(Compte $compte): self
    {
        $this->getComptes()->removeElement($compte);
        return $this;
    }


    #[ORM\OneToMany(targetEntity: Demande::class, mappedBy: 'employe')]
    private Collection $demandes;

    /**
     * @return Collection<int, Demande>
     */
    public function getDemandes(): Collection
    {
        if (!$this->demandes instanceof Collection) {
            $this->demandes = new ArrayCollection();
        }
        return $this->demandes;
    }

    public function addDemande(Demande $demande): self
    {
        if (!$this->getDemandes()->contains($demande)) {
            $this->getDemandes()->add($demande);
        }
        return $this;
    }

    public function removeDemande(Demande $demande): self
    {
        $this->getDemandes()->removeElement($demande);
        return $this;
    }
}