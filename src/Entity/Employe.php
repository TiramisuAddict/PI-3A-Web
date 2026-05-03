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

    #[ORM\Column(type: 'string', nullable: false)]
    private string $poste;

    public function getPoste(): string
    {
        return $this->poste;
    }

    public function setPoste(string $poste): self
    {
        $this->poste = $poste;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private string $role;

    public function getRole(): string
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

    #[ORM\Column(type: 'blob', columnDefinition: 'MEDIUMBLOB DEFAULT NULL', nullable: true)]
    /**
     * Blob column that Doctrine may hydrate as string or resource.
     * @var string|resource|null
     */
    private $cv_data = null;

    public function getCvData(): ?string
    {
        if (is_resource($this->cv_data)) {
            $data = stream_get_contents($this->cv_data);
            return $data === false ? null : $data;
        }

        return is_string($this->cv_data) ? $this->cv_data : null;
    }

    public function setCvData(string|null $cv_data): self
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


    /** @var Collection<int, Commentaire> */
    #[ORM\OneToMany(targetEntity: Commentaire::class, mappedBy: 'employe')]
    private Collection $commentaires;

    /** @var Collection<int, Demande> */
    #[ORM\OneToMany(targetEntity: Demande::class, mappedBy: 'employe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $demandes;

    /**
     * @return Collection<int, Commentaire>
     */
    public function getCommentaires(): Collection
    {
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

    #[ORM\OneToOne(targetEntity: CompetenceEmploye::class, mappedBy: 'employe')]
    private ?CompetenceEmploye $competenceEmploye = null;

    public function getCompetenceEmploye(): ?CompetenceEmploye
    {
        return $this->competenceEmploye;
    }

    public function setCompetenceEmploye(?CompetenceEmploye $competenceEmploye): self
    {
        $this->competenceEmploye = $competenceEmploye;
        return $this;
    }

    /** @var Collection<int, Compte> */
    #[ORM\OneToMany(targetEntity: Compte::class, mappedBy: 'employe')]
    private Collection $comptes;

    /**
     * @return Collection<int, Compte>
     */
    public function getComptes(): Collection
    {
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

    /** @var Collection<int, Projet> */
    #[ORM\OneToMany(targetEntity: Projet::class, mappedBy: 'responsable')]
    private Collection $projetsResponsables;

    /**
     * @return Collection<int, Projet>
     */
    public function getProjetsResponsables(): Collection
    {
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

    /** @var Collection<int, Projet> */
    #[ORM\ManyToMany(targetEntity: Projet::class, mappedBy: 'membresEquipe')]
    private Collection $projetsEquipe;

    /**
     * @return Collection<int, Projet>
     */
    public function getProjetsEquipe(): Collection
    {
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

    /**
     * @return Collection<int, Projet>
     */
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

    /**
     * @return Collection<int, Projet>
     */
    public function getProjetsMembre(): Collection
    {
        return $this->getProjetsEquipe();
    }

    /**
     * @return Collection<int, Projet>
     */
    public function getProjetsMembres(): Collection
    {
        return $this->getProjetsEquipe();
    }

    /**
     * @return Collection<int, Projet>
     */
    public function getProjetsEnEquipe(): Collection
    {
        return $this->getProjetsEquipe();
    }

    /** @var Collection<int, Tache> */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'employe')]
    private Collection $taches;

    public function __construct()
    {
        $this->commentaires = new ArrayCollection();
        $this->comptes = new ArrayCollection();
        $this->demandes = new ArrayCollection();
        $this->projetsResponsables = new ArrayCollection();
        $this->projetsEquipe = new ArrayCollection();
        $this->taches = new ArrayCollection();
        $this->projets1 = new ArrayCollection();
        $this->posts1 = new ArrayCollection();
    }

    /**
     * @return Collection<int, Tache>
     */
    public function getTaches(): Collection
    {
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
            $demande->setEmploye($this);
        }

        return $this;
    }

    public function removeDemande(Demande $demande): self
    {
        if ($this->getDemandes()->removeElement($demande) && $demande->getEmploye() === $this) {
            $demande->setEmploye(null);
        }

        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Projet::class, inversedBy: 'employes')]
    #[ORM\JoinTable(
        name: 'equipe_projet',
        joinColumns: [
            new ORM\JoinColumn(name: 'id_employe', referencedColumnName: 'id_employe')
        ],
        inverseJoinColumns: [
            new ORM\JoinColumn(name: 'id_projet', referencedColumnName: 'id_projet')
        ]
    )]
    private Collection $projets1;

    /**
     * @return Collection<int, Projet>
     */
    public function getProjets1(): Collection
    {
        return $this->projets1;
    }

    public function addProjet1(Projet $projet): self
    {
        if (!$this->getProjets1()->contains($projet)) {
            $this->getProjets1()->add($projet);
        }
        return $this;
    }

    public function removeProjet1(Projet $projet): self
    {
        $this->getProjets1()->removeElement($projet);
        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Post::class, inversedBy: 'employes')]
    #[ORM\JoinTable(
        name: 'like_post',
        joinColumns: [
            new ORM\JoinColumn(name: 'utilisateur_id', referencedColumnName: 'id_employe')
        ],
        inverseJoinColumns: [
            new ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id_post')
        ]
    )]
    private Collection $posts1;

    /**
     * @return Collection<int, Post>
     */
    public function getPosts1(): Collection
    {
        return $this->posts1;
    }

    public function addPost1(Post $post): self
    {
        if (!$this->getPosts1()->contains($post)) {
            $this->getPosts1()->add($post);
        }
        return $this;
    }

    public function removePost1(Post $post): self
    {
        $this->getPosts1()->removeElement($post);
        return $this;
    }

    /**
     * Face embedding vector from Face API (array of floats).
     * @var array<int, float>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $face_embedding = null;

    /**
     * @return array<int, float>|null
     */
    public function getFaceEmbedding(): ?array
    {
        return $this->face_embedding;
    }

    /**
     * @param array<int, float>|null $face_embedding
     */
    public function setFaceEmbedding(?array $face_embedding): self
    {
        $this->face_embedding = $face_embedding;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => false])]
    private bool $face_enabled = false;

    public function getFaceEnabled(): bool
    {
        return $this->face_enabled;
    }

    public function setFaceEnabled(bool $face_enabled): self
    {
        $this->face_enabled = $face_enabled;
        return $this;
    }

}
