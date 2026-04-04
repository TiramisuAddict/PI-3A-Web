<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use App\Repository\CandidatRepository;

#[ORM\Entity(repositoryClass: CandidatRepository::class)]
#[ORM\Table(
    name: 'candidat',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'code_candidature', columns: ['code_candidature'])],
    indexes: [
        new ORM\Index(name: 'fk_offre', columns: ['id_offre']),
        new ORM\Index(name: 'fk_v', columns: ['id_visiteur'])
    ]
)]
class Candidat
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

    #[ORM\Column(type: 'string', length: 50, nullable: false)]
    private ?string $code_candidature = null;

    public function getCode_candidature(): ?string
    {
        return $this->code_candidature;
    }

    public function setCode_candidature(string $code_candidature): self
    {
        $this->code_candidature = $code_candidature;
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

    //=========================================================
    #[ORM\Column(type: 'blob', columnDefinition: 'MEDIUMBLOB DEFAULT NULL', nullable: true)]
    private  mixed $cv_data = null;

    public function getCv_data(): ?string
    {
        if (is_resource($this->cv_data)) {
            $data = stream_get_contents($this->cv_data);
            return $data === false ? null : $data;
        }

        return is_string($this->cv_data) ? $this->cv_data : null;
    }

    public function setCv_data(?string $cv_data): self
    {
        $this->cv_data = $cv_data;
        return $this;
    }
    //=========================================================

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private mixed $lettre_motivation_nom = null;

    public function getLettre_motivation_nom(): ?string
    {
        return $this->lettre_motivation_nom;
    }

    public function setLettre_motivation_nom(?string $lettre_motivation_nom): self
    {
        $this->lettre_motivation_nom = $lettre_motivation_nom;
        return $this;
    }

    //=========================================================
    #[ORM\Column(type: 'blob', columnDefinition: 'MEDIUMBLOB DEFAULT NULL', nullable: true)]
    private  mixed $lettre_motivation_data = null;

    public function getLettre_motivation_data(): ?string
    {
        if (is_resource($this->lettre_motivation_data)) {
            $data = stream_get_contents($this->lettre_motivation_data);
            return $data === false ? null : $data;
        }

        return is_string($this->lettre_motivation_data) ? $this->lettre_motivation_data : null;
    }

    public function setLettre_motivation_data(?string $lettre_motivation_data): self
    {
        $this->lettre_motivation_data = $lettre_motivation_data;
        return $this;
    }
    //=========================================================

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

    #[ORM\Column(type: 'text', columnDefinition: 'TEXT DEFAULT NULL', nullable: true)]
    private ?string $note = null;

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $date_candidature = null;

    public function getDate_candidature(): ?\DateTimeInterface
    {
        return $this->date_candidature;
    }

    public function setDate_candidature(?\DateTimeInterface $date_candidature): self
    {
        $this->date_candidature = $date_candidature;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Offre::class, inversedBy: 'candidats')]
    #[ORM\JoinColumn(name: 'id_offre', referencedColumnName: 'id')]
    private ?Offre $offre = null;

    public function getOffre(): ?Offre
    {
        return $this->offre;
    }

    public function setOffre(?Offre $offre): self
    {
        $this->offre = $offre;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Visiteur::class, inversedBy: 'candidats')]
    #[ORM\JoinColumn(name: 'id_visiteur', referencedColumnName: 'id_visiteur')]
    private ?Visiteur $visiteur = null;

    public function getVisiteur(): ?Visiteur
    {
        return $this->visiteur;
    }

    public function setVisiteur(?Visiteur $visiteur): self
    {
        $this->visiteur = $visiteur;
        return $this;
    }

    #[ORM\Column(type: 'float', columnDefinition: 'FLOAT DEFAULT NULL', nullable: true)]
    private ?float $score = null;

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): self
    {
        $this->score = $score;
        return $this;
    }

}
