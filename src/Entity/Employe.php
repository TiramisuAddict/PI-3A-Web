<?php

namespace App\Entity;

use App\Repository\EmployeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmployeRepository::class)]
#[ORM\Table(name: 'employé')]
class Employe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_employe')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $nom = '';

    #[ORM\Column(length: 255)]
    private string $prenom = '';

    #[ORM\Column(name: 'e_mail', length: 255, unique: true)]
    private string $email = '';

    #[ORM\Column(type: 'integer')]
    private int $telephone = 0;

    #[ORM\Column(length: 255)]
    private string $poste = '';

    #[ORM\Column(length: 255)]
    private string $role = '';

    #[ORM\Column(name: 'date_embauche', type: 'date', nullable: true)]
    private ?\DateTimeInterface $dateEmbauche = null;

    #[ORM\Column(name: 'image_profil', length: 255, nullable: true)]
    private ?string $imageProfil = null;

    #[ORM\ManyToOne(targetEntity: Entreprise::class)]
    #[ORM\JoinColumn(name: 'id_entreprise', referencedColumnName: 'id_entreprise', nullable: false, onDelete: 'CASCADE')]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(name: 'cv_data', type: 'blob', nullable: true)]
    private $cvData = null;

    #[ORM\Column(name: 'cv_nom', length: 255, nullable: true)]
    private ?string $cvNom = null;

    #[ORM\ManyToOne(targetEntity: Candidat::class)]
    #[ORM\JoinColumn(name: 'id_candidat', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Candidat $candidat = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTelephone(): int
    {
        return $this->telephone;
    }

    public function setTelephone(int $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getPoste(): string
    {
        return $this->poste;
    }

    public function setPoste(string $poste): static
    {
        $this->poste = $poste;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getDateEmbauche(): ?\DateTimeInterface
    {
        return $this->dateEmbauche;
    }

    public function setDateEmbauche(?\DateTimeInterface $dateEmbauche): static
    {
        $this->dateEmbauche = $dateEmbauche;

        return $this;
    }

    public function getImageProfil(): ?string
    {
        return $this->imageProfil;
    }

    public function setImageProfil(?string $imageProfil): static
    {
        $this->imageProfil = $imageProfil;

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getCvData()
    {
        return $this->cvData;
    }

    public function setCvData($cvData): static
    {
        $this->cvData = $cvData;

        return $this;
    }

    public function getCvNom(): ?string
    {
        return $this->cvNom;
    }

    public function setCvNom(?string $cvNom): static
    {
        $this->cvNom = $cvNom;

        return $this;
    }

    public function getCandidat(): ?Candidat
    {
        return $this->candidat;
    }

    public function setCandidat(?Candidat $candidat): static
    {
        $this->candidat = $candidat;

        return $this;
    }
}
