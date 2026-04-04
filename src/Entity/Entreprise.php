<?php

namespace App\Entity;

use App\Repository\EntrepriseRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
#[ORM\Table(name: 'entreprise')]
class Entreprise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id_entreprise')]
    private ?int $id = null;

    #[ORM\Column(name: 'nom_entreprise', length: 255)]
    private string $nomEntreprise = '';

    #[ORM\Column(length: 255)]
    private string $pays = '';

    #[ORM\Column(length: 255)]
    private string $ville = '';

    #[ORM\Column(length: 255)]
    private string $nom = '';

    #[ORM\Column(length: 255)]
    private string $prenom = '';

    #[ORM\Column(name: 'matricule_fiscale', length: 255)]
    private string $matriculeFiscale = '';

    #[ORM\Column(type: 'integer')]
    private int $telephone = 0;

    #[ORM\Column(name: 'e_mail', length: 255)]
    private string $email = '';

    #[ORM\Column(length: 255)]
    private string $statut = '';

    #[ORM\Column(name: 'site_web', length: 255, nullable: true)]
    private ?string $siteWeb = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(name: 'date_demande', type: 'date')]
    private \DateTimeInterface $dateDemande;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNomEntreprise(): string
    {
        return $this->nomEntreprise;
    }

    public function setNomEntreprise(string $nomEntreprise): static
    {
        $this->nomEntreprise = $nomEntreprise;

        return $this;
    }

    public function getPays(): string
    {
        return $this->pays;
    }

    public function setPays(string $pays): static
    {
        $this->pays = $pays;

        return $this;
    }

    public function getVille(): string
    {
        return $this->ville;
    }

    public function setVille(string $ville): static
    {
        $this->ville = $ville;

        return $this;
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

    public function getMatriculeFiscale(): string
    {
        return $this->matriculeFiscale;
    }

    public function setMatriculeFiscale(string $matriculeFiscale): static
    {
        $this->matriculeFiscale = $matriculeFiscale;

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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getSiteWeb(): ?string
    {
        return $this->siteWeb;
    }

    public function setSiteWeb(?string $siteWeb): static
    {
        $this->siteWeb = $siteWeb;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getDateDemande(): \DateTimeInterface
    {
        return $this->dateDemande;
    }

    public function setDateDemande(\DateTimeInterface $dateDemande): static
    {
        $this->dateDemande = $dateDemande;

        return $this;
    }
}
