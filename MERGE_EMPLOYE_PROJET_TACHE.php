<?php
/*
 * ============================================================================
 * FONCTIONS À AJOUTER DANS Employé.php POUR LE MODULE PROJET / TACHE
 * ============================================================================
 *
 * Ce fichier contient les propriétés ORM, relations et méthodes à intégrer
 * dans l'entité Employé.php du projet final après fusion.
 *
 * INSTRUCTIONS :
 * 1. Ajouter les "use" manquants en haut de l'entité Employé.php
 * 2. Copier les propriétés ORM dans la classe Employé
 * 3. Ajouter les initialisations dans le __construct()
 * 4. Copier toutes les méthodes dans la classe Employé
 *
 * DÉPENDANCES :
 * - App\Entity\Projet (fichier Projet.php fourni)
 * - App\Entity\Tache  (fichier Tache.php fourni)
 *
 *
 * ============================================================================
 * 1. USE STATEMENTS (ajouter en haut du fichier si absents)
 * ============================================================================
 *
 *     use Doctrine\Common\Collections\ArrayCollection;
 *     use Doctrine\Common\Collections\Collection;
 *     use Doctrine\ORM\Mapping as ORM;
 *     use App\Entity\Projet;
 *     use App\Entity\Tache;
 *
 *
 * ============================================================================
 * 2. PROPRIÉTÉS ORM (ajouter dans la classe Employé)
 * ============================================================================
 *
 *     // --- Projets dont l'employé est responsable (chef de projet) ---
 *     #[ORM\OneToMany(targetEntity: Projet::class, mappedBy: 'responsable')]
 *     private Collection $projetsResponsables;
 *
 *     // --- Projets dont l'employé est membre d'équipe ---
 *     #[ORM\ManyToMany(targetEntity: Projet::class, mappedBy: 'membresEquipe')]
 *     private Collection $projetsEquipe;
 *
 *     // --- Tâches assignées à l'employé ---
 *     #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'employé')]
 *     private Collection $taches;
 *
 *
 * ============================================================================
 * 3. INITIALISATION DANS __construct() (ajouter ces lignes)
 * ============================================================================
 *
 *     $this->projetsResponsables = new ArrayCollection();
 *     $this->projetsEquipe = new ArrayCollection();
 *     $this->taches = new ArrayCollection();
 *
 *
 * ============================================================================
 * 4. MÉTHODES - PROJETS RESPONSABLE (OneToMany)
 * ============================================================================
 *
 *     public function getProjetsResponsables(): Collection
 *     {
 *         if (!$this->projetsResponsables instanceof Collection) {
 *             $this->projetsResponsables = new ArrayCollection();
 *         }
 *         return $this->projetsResponsables;
 *     }
 *
 *     public function addProjetResponsable(Projet $projet): self
 *     {
 *         if (!$this->getProjetsResponsables()->contains($projet)) {
 *             $this->getProjetsResponsables()->add($projet);
 *             $projet->setResponsable($this);
 *         }
 *         return $this;
 *     }
 *
 *     public function removeProjetResponsable(Projet $projet): self
 *     {
 *         if ($this->getProjetsResponsables()->removeElement($projet) && $projet->getResponsable() === $this) {
 *             $projet->setResponsable(null);
 *         }
 *         return $this;
 *     }
 *
 *     public function addProjetsResponsable(Projet $projetsResponsable): static
 *     {
 *         if (!$this->projetsResponsables->contains($projetsResponsable)) {
 *             $this->projetsResponsables->add($projetsResponsable);
 *             $projetsResponsable->setResponsable($this);
 *         }
 *         return $this;
 *     }
 *
 *     public function removeProjetsResponsable(Projet $projetsResponsable): static
 *     {
 *         if ($this->projetsResponsables->removeElement($projetsResponsable)) {
 *             if ($projetsResponsable->getResponsable() === $this) {
 *                 $projetsResponsable->setResponsable(null);
 *             }
 *         }
 *         return $this;
 *     }
 *
 *
 * ============================================================================
 * 5. MÉTHODES - PROJETS ÉQUIPE (ManyToMany)
 * ============================================================================
 *
 *     public function getProjetsEquipe(): Collection
 *     {
 *         if (!$this->projetsEquipe instanceof Collection) {
 *             $this->projetsEquipe = new ArrayCollection();
 *         }
 *         return $this->projetsEquipe;
 *     }
 *
 *     public function addProjetEquipe(Projet $projet): self
 *     {
 *         if (!$this->getProjetsEquipe()->contains($projet)) {
 *             $this->getProjetsEquipe()->add($projet);
 *             $projet->addMembreEquipe($this);
 *         }
 *         return $this;
 *     }
 *
 *     public function removeProjetEquipe(Projet $projet): self
 *     {
 *         if ($this->getProjetsEquipe()->removeElement($projet)) {
 *             $projet->removeMembreEquipe($this);
 *         }
 *         return $this;
 *     }
 *
 *     public function addProjetsEquipe(Projet $projetsEquipe): static
 *     {
 *         if (!$this->projetsEquipe->contains($projetsEquipe)) {
 *             $this->projetsEquipe->add($projetsEquipe);
 *             $projetsEquipe->addMembresEquipe($this);
 *         }
 *         return $this;
 *     }
 *
 *     public function removeProjetsEquipe(Projet $projetsEquipe): static
 *     {
 *         if ($this->projetsEquipe->removeElement($projetsEquipe)) {
 *             $projetsEquipe->removeMembresEquipe($this);
 *         }
 *         return $this;
 *     }
 *
 *
 * ============================================================================
 * 6. ALIAS DE COMPATIBILITÉ - PROJETS
 * ============================================================================
 *
 *     public function getProjets(): Collection
 *     {
 *         return $this->getProjetsResponsables();
 *     }
 *
 *     public function addProjet(Projet $projet): self
 *     {
 *         return $this->addProjetResponsable($projet);
 *     }
 *
 *     public function removeProjet(Projet $projet): self
 *     {
 *         return $this->removeProjetResponsable($projet);
 *     }
 *
 *     public function addProjetMembre(Projet $projet): self
 *     {
 *         return $this->addProjetEquipe($projet);
 *     }
 *
 *     public function removeProjetMembre(Projet $projet): self
 *     {
 *         return $this->removeProjetEquipe($projet);
 *     }
 *
 *     public function getProjetsMembre(): Collection
 *     {
 *         return $this->getProjetsEquipe();
 *     }
 *
 *     public function getProjetsMembres(): Collection
 *     {
 *         return $this->getProjetsEquipe();
 *     }
 *
 *     public function getProjetsEnEquipe(): Collection
 *     {
 *         return $this->getProjetsEquipe();
 *     }
 *
 *
 * ============================================================================
 * 7. MÉTHODES - TÂCHES (OneToMany)
 * ============================================================================
 *
 *     public function getTaches(): Collection
 *     {
 *         if (!$this->taches instanceof Collection) {
 *             $this->taches = new ArrayCollection();
 *         }
 *         return $this->taches;
 *     }
 *
 *     public function addTache(Tache $tache): self
 *     {
 *         if (!$this->getTaches()->contains($tache)) {
 *             $this->getTaches()->add($tache);
 *         }
 *         return $this;
 *     }
 *
 *     public function removeTache(Tache $tache): self
 *     {
 *         $this->getTaches()->removeElement($tache);
 *         return $this;
 *     }
 *
 *     public function addTach(Tache $tach): static
 *     {
 *         if (!$this->taches->contains($tach)) {
 *             $this->taches->add($tach);
 *             $tach->setEmployé($this);
 *         }
 *         return $this;
 *     }
 *
 *     public function removeTach(Tache $tach): static
 *     {
 *         if ($this->taches->removeElement($tach)) {
 *             if ($tach->getEmployé() === $this) {
 *                 $tach->setEmployé(null);
 *             }
 *         }
 *         return $this;
 *     }
 *
 * ============================================================================
 */