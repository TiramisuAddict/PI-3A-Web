<?php

namespace App\Entity;

use App\Repository\CandidatRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CandidatRepository::class)]
#[ORM\Table(name: 'candidat')]
class Candidat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'code_candidature', length: 255, unique: true)]
    private string $codeCandidature = '';

    #[ORM\Column(name: 'cv_nom', length: 255, nullable: true)]
    private ?string $cvNom = null;

    #[ORM\Column(name: 'cv_data', type: 'blob', nullable: true)]
    private $cvData = null;

    #[ORM\Column(name: 'lettre_motivation_nom', length: 255, nullable: true)]
    private ?string $lettreMotivationNom = null;

    #[ORM\Column(name: 'lettre_motivation_data', type: 'blob', nullable: true)]
    private $lettreMotivationData = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etat = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'date_candidature', type: 'date', nullable: true)]
    private ?\DateTimeInterface $dateCandidature = null;

    #[ORM\ManyToOne(targetEntity: Offre::class)]
    #[ORM\JoinColumn(name: 'id_offre', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Offre $offre = null;

    #[ORM\ManyToOne(targetEntity: Visiteur::class)]
    #[ORM\JoinColumn(name: 'id_visiteur', referencedColumnName: 'id_visiteur', nullable: false, onDelete: 'CASCADE')]
    private ?Visiteur $visiteur = null;

    #[ORM\Column(nullable: true)]
    private ?float $score = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodeCandidature(): string
    {
        return $this->codeCandidature;
    }

    public function setCodeCandidature(string $codeCandidature): static
    {
        $this->codeCandidature = $codeCandidature;

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

    public function getCvData()
    {
        return $this->cvData;
    }

    public function setCvData($cvData): static
    {
        $this->cvData = $cvData;

        return $this;
    }

    public function getLettreMotivationNom(): ?string
    {
        return $this->lettreMotivationNom;
    }

    public function setLettreMotivationNom(?string $lettreMotivationNom): static
    {
        $this->lettreMotivationNom = $lettreMotivationNom;

        return $this;
    }

    public function getLettreMotivationData()
    {
        return $this->lettreMotivationData;
    }

    public function setLettreMotivationData($lettreMotivationData): static
    {
        $this->lettreMotivationData = $lettreMotivationData;

        return $this;
    }

    public function getEtat(): ?string
    {
        return $this->etat;
    }

    public function setEtat(?string $etat): static
    {
        $this->etat = $etat;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getDateCandidature(): ?\DateTimeInterface
    {
        return $this->dateCandidature;
    }

    public function setDateCandidature(?\DateTimeInterface $dateCandidature): static
    {
        $this->dateCandidature = $dateCandidature;

        return $this;
    }

    public function getOffre(): ?Offre
    {
        return $this->offre;
    }

    public function setOffre(?Offre $offre): static
    {
        $this->offre = $offre;

        return $this;
    }

    public function getVisiteur(): ?Visiteur
    {
        return $this->visiteur;
    }

    public function setVisiteur(?Visiteur $visiteur): static
    {
        $this->visiteur = $visiteur;

        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): static
    {
        $this->score = $score;

        return $this;
    }
}
