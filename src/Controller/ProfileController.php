<?php

namespace App\Controller;

use App\Entity\CompetenceEmploye;
use App\Form\EmployeType;
use App\Repository\AdministrateurSystemeRepository;
use App\Repository\EmployeRepository;
use App\Repository\EntrepriseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Doctrine\ORM\EntityManagerInterface;

final class ProfileController extends AbstractController
{
    /**
     * @return list<string>
     */
    private function decodeSkills(?string $skillsJson): array
    {
        $decoded = json_decode($skillsJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $skills = [];
        foreach ($decoded as $skill) {
            $value = trim($skill);
            if ($value !== '') {
                $skills[] = $value;
            }
        }

        return array_unique($skills);
    }

    #[Route('/profil', name: 'profil', methods: ['GET', 'POST'])]
    public function index(Request $request,SessionInterface $session,EmployeRepository $employeRepository,EntityManagerInterface $entityManager,EntrepriseRepository $entrepriseRepository,AdministrateurSystemeRepository $administrateurSystemeRepository): Response {
    

        if ($session->get('employe_logged_in') === true) {
            $employeId = $session->get('employe_id');
            $employe = $employeId !== null && (int) $employeId > 0 ? $employeRepository->find((int) $employeId) : null;
            $profileForm = $this->createForm(EmployeType::class, $employe);
            $profileForm->remove('poste');
            $profileForm->remove('role');
            $profileForm->remove('date_embauche');
            $profileForm->handleRequest($request);

            if ($profileForm->isSubmitted() && $profileForm->isValid()) {
                $otherEmploye = $employeRepository->findOneBy(['e_mail' => $employe->getEmail()]);
                if ($otherEmploye !== null && $otherEmploye->getId_employe() !== $employe->getId_employe()) {
                    $profileForm->get('e_mail')->addError(new FormError('Cet email est déjà utilisé.'));
                } else {
                    $cvFile = $profileForm->get('cv_data')->getData();
                    if ($cvFile instanceof UploadedFile) {
                        $cvContent = file_get_contents($cvFile->getPathname());
                        if ($cvContent === false) {
                            $this->addFlash('error', 'Impossible de lire le fichier CV uploadé.');
                            return $this->redirectToRoute('profil');
                        }

                        $employe->setCv_nom($cvFile->getClientOriginalName());
                        $employe->setCv_data($cvContent);
                    }

                    $entityManager->flush();
                    $session->set('employe_email', $employe->getEmail());
                    $this->addFlash('success', 'Profil mis à jour avec succès.');

                    return $this->redirectToRoute('profil');
                }
            }

            $entreprise = $employe->getEntreprise() ?? $entrepriseRepository->find($session->get('employe_id_entreprise'));
            $skills = $this->decodeSkills($employe->getCompetenceEmploye()?->getSkills());

            return $this->render('profil/show.html.twig', [
                'profile_type' => 'employee',
                'role' => $session->get('employe_role'),
                'email' => $session->get('employe_email'),
                'employe' => $employe,
                'skills' => $skills,
                'profile_form' => $profileForm->createView(),
                'entreprise' => $entreprise,
                'back_route' => in_array($session->get('employe_role'), ['RH', 'administrateur entreprise'], true) ? 'RH_Home' : 'employe_Home',
                'show_navbar' => true,
            ]);
        }

        return $this->redirectToRoute('login');
    }

    #[Route('/profil/competence/add', name: 'profil_competence_add', methods: ['POST'])]
    public function addSkill(Request $request, SessionInterface $session, EmployeRepository $employeRepository, EntityManagerInterface $entityManager): Response
    {
        if ($session->get('employe_logged_in') !== true) {
            return $this->redirectToRoute('login');
        }

        if (!$this->isCsrfTokenValid('profil_competence_add', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('profil');
        }

        $skill = trim((string) $request->request->get('skill', ''));
        if ($skill === '') {
            $this->addFlash('error', 'Veuillez saisir une compétence.');
            return $this->redirectToRoute('profil');
        }

        $employeId = (int) $session->get('employe_id', 0);
        $employe = $employeId > 0 ? $employeRepository->find($employeId) : null;
        if ($employe === null) {
            return $this->redirectToRoute('login');
        }

        $competenceEmploye = $employe->getCompetenceEmploye();
        if ($competenceEmploye === null) {
            $competenceEmploye = new CompetenceEmploye();
            $competenceEmploye->setEmploye($employe);
            $employe->setCompetenceEmploye($competenceEmploye);
            $entityManager->persist($competenceEmploye);
        }

        $skills = $this->decodeSkills($competenceEmploye->getSkills());
        if (!in_array($skill, $skills, true)) {
            $skills[] = $skill;
            $competenceEmploye->setSkills(json_encode($skills, JSON_UNESCAPED_UNICODE));
            $entityManager->flush();
            $this->addFlash('success', 'Compétence ajoutée.');
        }

        return $this->redirectToRoute('profil');
    }

    #[Route('/profil/competence/remove', name: 'profil_competence_remove', methods: ['POST'])]
    public function removeSkill(Request $request, SessionInterface $session, EmployeRepository $employeRepository, EntityManagerInterface $entityManager): Response
    {
        if ($session->get('employe_logged_in') !== true) {
            return $this->redirectToRoute('login');
        }

        if (!$this->isCsrfTokenValid('profil_competence_remove', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('profil');
        }

        $skillToRemove = trim((string) $request->request->get('skill', ''));
        if ($skillToRemove === '') {
            return $this->redirectToRoute('profil');
        }

        $employeId = (int) $session->get('employe_id', 0);
        $employe = $employeId > 0 ? $employeRepository->find($employeId) : null;
        if ($employe === null || $employe->getCompetenceEmploye() === null) {
            return $this->redirectToRoute('profil');
        }

        $competenceEmploye = $employe->getCompetenceEmploye();
        $skills = $this->decodeSkills($competenceEmploye->getSkills());
        $skills = array_values(array_filter($skills, static fn (string $value): bool => $value !== $skillToRemove));

        $competenceEmploye->setSkills(json_encode($skills, JSON_UNESCAPED_UNICODE));
        $entityManager->flush();

        return $this->redirectToRoute('profil');
    }

    #[Route('/profil/upload-image', name: 'profil_upload_image', methods: ['POST'])]
    public function uploadImage( Request $request, SessionInterface $session,EmployeRepository $employeRepository,EntityManagerInterface $entityManager,SluggerInterface $slugger): JsonResponse {

        $employeId = (int) $session->get('employe_id', 0);
        $employe = $employeId > 0 ? $employeRepository->find($employeId) : null;
        $file = $request->files->get('image');
        
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['success' => false, 'error' => 'Aucun fichier fourni'], 400);
        }

        // Valider que c'est une image
        $mimeType = $file->getMimeType();
        $allowedMimetypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    

        // Vérifier la taille (max 5MB)
        $maxSize = 5 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return new JsonResponse(['success' => false, 'error' => 'Image trop volumineuse (max 5MB)'], 400);
        }

       
            // Créer le répertoire s'il n'existe pas
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/images/profils';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Générer un nom unique pour le fichier
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $slugger->slug($originalFilename);
            $filename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

            // Déplacer le fichier
            $file->move($uploadDir, $filename);

            // Supprimer l'ancienne image si elle existe
            $oldImage = $employe->getImage_profil();
            if ($oldImage !== null && $oldImage !== '') {
                $oldImagePath = $this->getParameter('kernel.project_dir') . '/public' . $oldImage;
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }

            // Mettre à jour l'entité avec le chemin de la nouvelle image
            $imagePath = '/images/profils/' . $filename;
            $employe->setImage_profil($imagePath);
            
            // Persister les changements
            $entityManager->persist($employe);
            $entityManager->flush();

            return new JsonResponse(['success' => true, 'message' => 'Image mise à jour avec succès']);
        
    }

}