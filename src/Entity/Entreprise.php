<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\EntrepriseRepository;

#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
#[ORM\Table(name: 'entreprise')]
class Entreprise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_entreprise = null;

    public function getId_entreprise(): ?int
    {
        return $this->id_entreprise;
    }

    public function setId_entreprise(int $id_entreprise): self
    {
        $this->id_entreprise = $id_entreprise;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $nom_entreprise = null;

    public function getNom_entreprise(): ?string
    {
        return $this->nom_entreprise;
    }

    public function setNom_entreprise(string $nom_entreprise): self
    {
        $this->nom_entreprise = $nom_entreprise;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $pays = null;

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(string $pays): self
    {
        $this->pays = $pays;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $ville = null;

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(string $ville): self
    {
        $this->ville = $ville;
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
    private ?string $matricule_fiscale = null;

    public function getMatricule_fiscale(): ?string
    {
        return $this->matricule_fiscale;
    }

    public function setMatricule_fiscale(string $matricule_fiscale): self
    {
        $this->matricule_fiscale = $matricule_fiscale;
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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $statut = null;

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $site_web = null;

    public function getSite_web(): ?string
    {
        return $this->site_web;
    }

    public function setSite_web(?string $site_web): self
    {
        $this->site_web = $site_web;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $logo = null;

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $date_demande = null;

    public function getDate_demande(): ?\DateTimeInterface
    {
        return $this->date_demande;
    }

    public function setDate_demande(\DateTimeInterface $date_demande): self
    {
        $this->date_demande = $date_demande;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Employé::class, mappedBy: 'entreprise')]
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
