<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\EmployeRepository;
use App\Repository\AdministrateurSystemeRepository;
use App\Entity\Employe;
use App\Form\EmployeType;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\CvExtractionService;
use App\Service\MailerService;
use App\Service\PasswordGenerator;
use App\Service\EmployeCsvImportService;
use App\Entity\Compte;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Knp\Component\Pager\PaginatorInterface;




final class RHController extends AbstractController
{
    private function isEmployeLoggedIn(SessionInterface $session): bool
    {
        return $session->get('employe_logged_in') === true;
    }

#[Route('/RH/Home', name: 'RH_Home', methods: ['GET'])]
public function dashboard(Request $request, SessionInterface $session, EmployeRepository $employeRepo, EntrepriseRepository $entrepriseRepo, PaginatorInterface $paginator): Response
{
    if (!$this->isEmployeLoggedIn($session)) {
        return $this->redirectToRoute('login');
    }

    $idEntreprise = (int) $session->get('employe_id_entreprise', 0);
    $entreprise = $idEntreprise > 0 ? $entrepriseRepo->find($idEntreprise) : null;
    if ($entreprise === null) {
        return $this->redirectToRoute('login');
    }

    $searchValue = $request->query->get('search');
    $roleValue = $request->query->get('role');
    $search = is_string($searchValue) && trim($searchValue) !== '' ? $searchValue : null;
    $role = is_string($roleValue) && trim($roleValue) !== '' ? $roleValue : null;
    $employes = $paginator->paginate(
        $employeRepo->createFilteredQueryBuilder($entreprise, $search, $role),
        max(1, (int) $request->query->get('page', 1)),
        8
    );

    $formAjout = $this->createForm(EmployeType::class, new Employe());

    return $this->render('rh/Home.html.twig', [
        'employes' => $employes,
        'form_ajout' => $formAjout->createView(),
        'role' => $session->get('employe_role'),
        'email' => $session->get('employe_email'),
        'search' => $search,
        'selected_role' => $role,
    ]);
}
    #[Route('/employe/{id}/details', name: 'employe_details', methods: ['GET', 'POST'])]
    public function details(
        int $id,
        Request $request,
        EmployeRepository $employeRepo,
        EntityManagerInterface $em,
        SessionInterface $session,
        EntrepriseRepository $entrepriseRepo,
        CvExtractionService $cvExtractionService,
        PaginatorInterface $paginator,
    ): Response {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employe = $employeRepo->find($id);
        if ($employe === null) {
            throw $this->createNotFoundException('Employé introuvable.');
        }
        $form = $this->createForm(EmployeType::class, $employe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cvFile = $form->get('cv_data')->getData();
            if ($cvFile instanceof UploadedFile) {
                $cvContent = file_get_contents($cvFile->getPathname());
                if ($cvContent === false) {
                    $this->addFlash('error', 'Impossible de lire le fichier CV uploadé.');
                    return $this->redirectToRoute('employe_details', ['id' => $id]);
                }

                $employe->setCv_nom($cvFile->getClientOriginalName());
                $employe->setCvData($cvContent);

                $extractionResult = $cvExtractionService->extractAndPersistForEmploye($employe, $em);
                if (($extractionResult['success'] ?? false) !== true) {
                    $this->addFlash('warning', 'CV enregistre, extraction non effectuee: ' . ($extractionResult['error'] ?? 'erreur inconnue'));
                } else {
                    $this->addFlash('success', sprintf(
                        'Extraction CV terminee: %d skill(s), %d formation(s), %d experience(s).',
                        $extractionResult['data']['skills_count'] ?? 0,
                        $extractionResult['data']['formations_count'] ?? 0,
                        $extractionResult['data']['experience_count'] ?? 0
                    ));
                }
            }

            $em->flush();
            $this->addFlash('success', 'Employé modifié avec succès.');
            return $this->redirectToRoute('employe_details', ['id' => $id]);
        }

        $idEntreprise = (int) $session->get('employe_id_entreprise', 0);
        $entreprise = $idEntreprise > 0 ? $entrepriseRepo->find($idEntreprise) : null;
        if ($entreprise === null) {
            return $this->redirectToRoute('login');
        }
        $searchValue = $request->query->get('search');
        $roleValue = $request->query->get('role');
        $search = is_string($searchValue) && trim($searchValue) !== '' ? $searchValue : null;
        $role = is_string($roleValue) && trim($roleValue) !== '' ? $roleValue : null;
        $employes = $paginator->paginate(
            $employeRepo->createFilteredQueryBuilder($entreprise, $search, $role),
            max(1, (int) $request->query->get('page', 1)),
            8
        );

        $formAjout = $this->createForm(EmployeType::class, new Employe());

        return $this->render('rh/Home.html.twig', [
            'employes' => $employes,
            'employe_select'=> $employe,
            'form' => $form->createView(),
            'form_ajout'=> $formAjout->createView(),
            'role'=> $session->get('employe_role'),
            'email' => $session->get('employe_email'),
            'search' => $search,
            'selected_role'=> $role,
        ]);
    }
    #[Route('/employe/ajouter', name: 'employe_ajouter', methods: ['GET', 'POST'])]
    public function ajouter(Request $request,EntityManagerInterface $em,SessionInterface $session,EmployeRepository $employeRepo,EntrepriseRepository $entrepriseRepo,PasswordGenerator $passwordGenerator,CvExtractionService $cvExtractionService,MailerInterface $mailer,MailerService $mailerService,UserPasswordHasherInterface $passwordHasher, PaginatorInterface $paginator): Response {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employe = new Employe();
        $form = $this->createForm(EmployeType::class, $employe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cvFile = $form->get('cv_data')->getData();
            if ($cvFile instanceof UploadedFile) {
                $cvContent = file_get_contents($cvFile->getPathname());
                if ($cvContent === false) {
                    $this->addFlash('error', 'Impossible de lire le fichier CV uploadé.');
                    return $this->redirectToRoute('employe_ajouter');
                }

                $employe->setCv_nom($cvFile->getClientOriginalName());
                $employe->setCvData($cvContent);

                $extractionResult = $cvExtractionService->extractAndPersistForEmploye($employe, $em);
                if (($extractionResult['success'] ?? false) !== true) {
                    $this->addFlash('warning', 'CV enregistre, extraction non effectuee: ' . ($extractionResult['error'] ?? 'erreur inconnue'));
                } else {
                    $this->addFlash('success', sprintf(
                        'Extraction CV terminee: %d skill(s), %d formation(s), %d experience(s).',
                        $extractionResult['data']['skills_count'] ?? 0,
                        $extractionResult['data']['formations_count'] ?? 0,
                        $extractionResult['data']['experience_count'] ?? 0
                    ));
                }
            }

            $idEntreprise = (int) $session->get('employe_id_entreprise', 0);
            $entreprise   = $idEntreprise > 0 ? $entrepriseRepo->find($idEntreprise) : null;
            if ($entreprise === null) {
                $this->addFlash('error', 'Entreprise introuvable pour cet utilisateur.');
                return $this->redirectToRoute('RH_Home');
            }
            $employe->setEntreprise($entreprise);

            $em->persist($employe);
            $plainPassword = $passwordGenerator->generatePlain();
            $compte = new Compte();
                    $compte->setMot_de_passe($passwordGenerator->hash($plainPassword));
            $compte->setEmploye($employe);
            $em->persist($compte);
            $em->flush();

            try {
                $mailerService->sendTemporaryPassword(
                    $mailer,
                    $employe->getEmail(),
                    $employe->getPrenom(),
                    $employe->getNom(),
                    $plainPassword
                );
                $this->addFlash('success', 'Mot de passe envoye par e-mail.');
            } catch (\Throwable $exception) {
                $this->addFlash('warning', 'Employé créé, mais l\'envoi de l\'e-mail a échoué.');
            }

            $this->addFlash('success', 'Employé ajouté avec succès.');
            return $this->redirectToRoute('RH_Home');
        }

        $idEntreprise = (int) $session->get('employe_id_entreprise', 0);
        $entreprise = $idEntreprise > 0 ? $entrepriseRepo->find($idEntreprise) : null;
        if ($entreprise === null) {
            return $this->redirectToRoute('login');
        }
        $searchValue = $request->query->get('search');
        $roleValue = $request->query->get('role');
        $search = is_string($searchValue) && trim($searchValue) !== '' ? $searchValue : null;
        $role = is_string($roleValue) && trim($roleValue) !== '' ? $roleValue : null;
        $employes = $paginator->paginate(
            $employeRepo->createFilteredQueryBuilder($entreprise, $search, $role),
            max(1, (int) $request->query->get('page', 1)),
            8
        );

        return $this->render('rh/Home.html.twig', [
            'employes'=> $employes,
            'form_ajout'=> $form->createView(),
            'show_ajout'=> true,
            'role' => $session->get('employe_role'),
            'email'=> $session->get('employe_email'),
            'search'=> $search,
            'selected_role'  => $role,
        ]);
    }

    #[Route('/employe/import-csv', name: 'employe_import_csv', methods: ['POST'])]
    public function importCsv(Request $request,SessionInterface $session,EntrepriseRepository $entrepriseRepo,EmployeRepository $employeRepo,EntityManagerInterface $em,PasswordGenerator $passwordGenerator,EmployeCsvImportService $employeCsvImportService,MailerInterface $mailer,MailerService $mailerService,UserPasswordHasherInterface $passwordHasher): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $file = $request->files->get('employees_csv');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Veuillez sélectionner un fichier CSV.');
            return $this->redirectToRoute('employe_ajouter');
        }

        $idEntreprise = (int) $session->get('employe_id_entreprise', 0);
        $entreprise = $idEntreprise > 0 ? $entrepriseRepo->find($idEntreprise) : null;
        if ($entreprise === null) {
            $this->addFlash('error', 'Entreprise introuvable pour cet utilisateur.');
            return $this->redirectToRoute('RH_Home');
        }
        $result = $employeCsvImportService->import($file, $entreprise, $employeRepo, $em, $passwordGenerator, $mailer, $mailerService, $passwordHasher);
        if ($result['fatalError'] !== null) {
            $this->addFlash('error', $result['fatalError']);
            return $this->redirectToRoute('employe_ajouter');
        }

        $imported = $result['imported'];
        $errors = $result['errors'];

        if ($imported > 0) {
            $this->addFlash('success', sprintf('Import CSV terminé: %d employé(s) ajouté(s).', $imported));
        }

        if (count($errors) > 0) {
            $previewErrors = array_slice($errors, 0, 5);
            $message = implode(' | ', $previewErrors);
            if (count($errors) > 5) {
                $message .= sprintf(' | ... et %d autre(s) erreur(s).', count($errors) - 5);
            }
            $this->addFlash('warning', $message);
        }

        if ($imported === 0 && count($errors) === 0) {
            $this->addFlash('warning', 'Aucune donnée exploitable trouvée dans le CSV.');
        }

        return $this->redirectToRoute('RH_Home');
    }
    #[Route('/employe/{id}/supprimer', name: 'employe_supprimer', methods: ['POST'])]
    public function supprimer(int $id,EmployeRepository $employeRepo,EntityManagerInterface $em,SessionInterface $session): Response {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employe = $employeRepo->find($id);
        if ($employe === null) {
            throw $this->createNotFoundException('Employé introuvable.');
        }

        foreach ($employe->getComptes() as $compte) {
            $em->remove($compte);
        }

        $em->remove($employe);
        $em->flush();

        $this->addFlash('success', 'Employé supprimé avec succès.');
        return $this->redirectToRoute('RH_Home');
    }

    #[Route('/employe/{id}/cv', name: 'app_employe_download_cv', methods: ['GET'])]
    public function downloadCv(int $id, EmployeRepository $employeRepo, SessionInterface $session): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employe = $employeRepo->find($id);
        $cvData = $employe?->getCvData();

        if ($employe === null || $cvData === null || $cvData === '') {
            throw $this->createNotFoundException('CV introuvable.');
        }

        $filename = $employe->getCv_nom();
        if ($filename === null || $filename === '') {
            $filename = 'cv-employe-' . $id . '.pdf';
        }

        $response = new Response($cvData);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"');

        return $response;
    }

}
