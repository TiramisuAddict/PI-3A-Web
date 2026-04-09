<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
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

    #[ORM\Column(type: 'string', length: 30, nullable: false)]
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

    #[ORM\Column(type: 'string', length: 30, nullable: false)]
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

    public function getDate_embauche(): ?\DateTimeInterface
    {
        return $this->date_embauche;
    }

    public function setDate_embauche(?\DateTimeInterface $date_embauche): self
    {
        $this->date_embauche = $date_embauche;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
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

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
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

    #[ORM\OneToMany(targetEntity: Projet::class, mappedBy: 'responsable')]
    private Collection $projetsResponsables;

    /**
     * @return Collection<int, Projet>
     */
    public function getProjetsResponsables(): Collection
    {
        if (!$this->projetsResponsables instanceof Collection) {
            $this->projetsResponsables = new ArrayCollection();
        }

        return $this->projetsResponsables;
    }

    public function addProjetResponsable(Projet $projet): self
    {
        if (!$this->getProjetsResponsables()->contains($projet)) {
            $this->getProjetsResponsables()->add($projet);
            $projet->setResponsable($this);
        }

        return $this;
    }

    public function removeProjetResponsable(Projet $projet): self
    {
        if ($this->getProjetsResponsables()->removeElement($projet) && $projet->getResponsable() === $this) {
            $projet->setResponsable(null);
        }

        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Projet::class, mappedBy: 'membresEquipe')]
    private Collection $projetsEquipe;

    /**
     * @return Collection<int, Projet>
     */
    public function getProjetsEquipe(): Collection
    {
        if (!$this->projetsEquipe instanceof Collection) {
            $this->projetsEquipe = new ArrayCollection();
        }

        return $this->projetsEquipe;
    }

    public function addProjetEquipe(Projet $projet): self
    {
        if (!$this->getProjetsEquipe()->contains($projet)) {
            $this->getProjetsEquipe()->add($projet);
            $projet->addMembreEquipe($this);
        }

        return $this;
    }

    public function removeProjetEquipe(Projet $projet): self
    {
        if ($this->getProjetsEquipe()->removeElement($projet)) {
            $projet->removeMembreEquipe($this);
        }

        return $this;
    }

    public function getProjets(): Collection
    {
        return $this->getProjetsResponsables();
    }

    public function addProjet(Projet $projet): self
    {
        return $this->addProjetResponsable($projet);
    }

    public function removeProjet(Projet $projet): self
    {
        return $this->removeProjetResponsable($projet);
    }

    public function addProjetMembre(Projet $projet): self
    {
        return $this->addProjetEquipe($projet);
    }

    public function removeProjetMembre(Projet $projet): self
    {
        return $this->removeProjetEquipe($projet);
    }

    public function getProjetsMembre(): Collection
    {
        return $this->getProjetsEquipe();
    }

    public function getProjetsMembres(): Collection
    {
        return $this->getProjetsEquipe();
    }

    public function getProjetsEnEquipe(): Collection
    {
        return $this->getProjetsEquipe();
    }

    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'employe')]
    private Collection $taches;

    public function __construct()
    {
        $this->comptes = new ArrayCollection();
        $this->projetsResponsables = new ArrayCollection();
        $this->projetsEquipe = new ArrayCollection();
        $this->taches = new ArrayCollection();
    }

    /**
     * @return Collection<int, Tache>
     */
    public function getTaches(): Collection
    {
        if (!$this->taches instanceof Collection) {
            $this->taches = new ArrayCollection();
        }
        return $this->taches;
    }

    public function addTache(Tache $tache): self
    {
        if (!$this->getTaches()->contains($tache)) {
            $this->getTaches()->add($tache);
        }
        return $this;
    }

    public function removeTache(Tache $tache): self
    {
        $this->getTaches()->removeElement($tache);
        return $this;
    }

    public function getIdEmploye(): ?int
    {
        return $this->id_employe;
    }

    public function getEMail(): ?string
    {
        return $this->e_mail;
    }

    public function setEMail(string $e_mail): static
    {
        $this->e_mail = $e_mail;

        return $this;
    }

    public function getDateEmbauche(): ?\DateTime
    {
        return $this->date_embauche;
    }

    public function setDateEmbauche(?\DateTime $date_embauche): static
    {
        $this->date_embauche = $date_embauche;

        return $this;
    }

    public function getImageProfil(): ?string
    {
        return $this->image_profil;
    }

    public function setImageProfil(?string $image_profil): static
    {
        $this->image_profil = $image_profil;

        return $this;
    }

    public function getCvData(): ?string
    {
        return $this->cv_data;
    }

    public function setCvData(?string $cv_data): static
    {
        $this->cv_data = $cv_data;

        return $this;
    }

    public function getCvNom(): ?string
    {
        return $this->cv_nom;
    }

    public function setCvNom(?string $cv_nom): static
    {
        $this->cv_nom = $cv_nom;

        return $this;
    }

    public function addProjetsResponsable(Projet $projetsResponsable): static
    {
        if (!$this->projetsResponsables->contains($projetsResponsable)) {
            $this->projetsResponsables->add($projetsResponsable);
            $projetsResponsable->setResponsable($this);
        }

        return $this;
    }

    public function removeProjetsResponsable(Projet $projetsResponsable): static
    {
        if ($this->projetsResponsables->removeElement($projetsResponsable)) {
            if ($projetsResponsable->getResponsable() === $this) {
                $projetsResponsable->setResponsable(null);
            }
        }

        return $this;
    }

    public function addProjetsEquipe(Projet $projetsEquipe): static
    {
        if (!$this->projetsEquipe->contains($projetsEquipe)) {
            $this->projetsEquipe->add($projetsEquipe);
            $projetsEquipe->addMembresEquipe($this);
        }

        return $this;
    }

    public function removeProjetsEquipe(Projet $projetsEquipe): static
    {
        if ($this->projetsEquipe->removeElement($projetsEquipe)) {
            $projetsEquipe->removeMembresEquipe($this);
        }

        return $this;
    }

    public function addTach(Tache $tach): static
    {
        if (!$this->taches->contains($tach)) {
            $this->taches->add($tach);
            $tach->setEmploye($this);
        }

        return $this;
    }

    public function removeTach(Tache $tach): static
    {
        if ($this->taches->removeElement($tach)) {
            if ($tach->getEmploye() === $this) {
                $tach->setEmploye(null);
            }
        }

        return $this;
    }
}
