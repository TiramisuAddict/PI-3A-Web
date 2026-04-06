<?php

namespace App\Controller;

use App\Entity\Candidat;
use App\Form\PostulerType;
use App\Repository\CandidatRepository;
use App\Repository\OffreRepository;
use App\Repository\VisiteurRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Form\CandidatType;

final class CandidatController extends AbstractController
{
    #[Route('/candidat', name: 'app_candidat')]
    public function index(): Response
    {
        return $this->render('candidat/index.html.twig', [
            'controller_name' => 'CandidatController',
        ]);
    }

    #[Route('/candidature/suivre', name: 'app_suivre_candidature')]
    public function suivre_candidature(CandidatRepository $candidat_repository): Response
    {
        $candidatures = $candidat_repository->findAll();

        return $this->render('candidat/suivre_candidature_page.html.twig', [
            'candidatures' => $candidatures,
        ]);
    }

    #[Route('/candidature/confirmation/{code}', name: 'app_candidature_confirmation', methods: ['GET'])]
    public function confirmation(CandidatRepository $candidatRepository, string $code): Response
    {
        $candidature = $candidatRepository->findOneBy(['code_candidature' => $code]);

        if (!$candidature) {
            throw $this->createNotFoundException('Candidature introuvable.');
        }

        return $this->render('candidat/confirmation_candidature.html.twig', [
            'code' => $code,
            'candidature' => $candidature,
        ]);
    }

    #[Route('/candidature/postuler/{offreId}/{visiteurId}', name: 'app_candidature_postuler', methods: ['GET', 'POST'])]
    public function postuler(
        Request $request,
        ManagerRegistry $doctrine,
        OffreRepository $offreRepository,
        VisiteurRepository $visiteurRepository,
        int $offreId,
        int $visiteurId
    ): Response {
        $offre = $offreRepository->find($offreId);
        $visiteur = $visiteurRepository->find($visiteurId);

        if (!$offre || !$visiteur) {
            return new Response('Offre ou visiteur introuvable.', Response::HTTP_NOT_FOUND);
        }

        $candidat = new Candidat();
        $form = $this->createForm(PostulerType::class, $candidat, [
            'action' => $this->generateUrl('app_candidature_postuler', [
                'offreId' => $offreId,
                'visiteurId' => $visiteurId,
            ]),
            'method' => 'POST',
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cvFile = $form->get('cv_data')->getData();
            $lettreFile = $form->get('lettre_motivation_data')->getData();

            if (!$cvFile || !$lettreFile) {
                $this->addFlash('error', 'CV et lettre de motivation sont requis.');
                return $this->redirectToRoute('app_offre_list');
            }

            $cvContent = file_get_contents($cvFile->getPathname());
            $lettreContent = file_get_contents($lettreFile->getPathname());

            if ($cvContent === false || $lettreContent === false) {
                $this->addFlash('error', 'Impossible de lire les fichiers uploades.');
                return $this->redirectToRoute('app_offre_list');
            }

            $code = strtoupper(base_convert((string) round(microtime(true) * 1000), 10, 36));

            $candidat
                ->setCodeCandidature($code)
                ->setEtat('En attente')
                ->setNote(null)
                ->setDateCandidature(new \DateTime())
                ->setCvNom($cvFile->getClientOriginalName())
                ->setCvData($cvContent)
                ->setLettreMotivationNom($lettreFile->getClientOriginalName())
                ->setLettreMotivationData($lettreContent)
                ->setOffre($offre)
                ->setVisiteur($visiteur);

            $entityManager = $doctrine->getManager();
            $entityManager->persist($candidat);
            $entityManager->flush();

            return $this->redirectToRoute('app_candidature_confirmation', [
                'code' => $code,
            ]);
        }

        return $this->render('candidat/_postuler.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route ('/candidature/modifier/{id}', name: 'app_candidature_modifier')]
    public function modifierCandidature(Request $request, ManagerRegistry $doctrine, $id){
        $candidat = $doctrine->getRepository(Candidat::class)->find($id);

        $form = $this->createForm(CandidatType::class, $candidat);
       
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $candidat = $form->getData();
            $doctrine->getManager()->flush($candidat);

            return $this->render('candidat/_test_form_candidat.html.twig', [
                'form' => $form->createView(),
                'message' => 'Candidature modifiée avec succès !',
            ]);

        }

        return $this->render('candidat/_test_form_candidat.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #
}
