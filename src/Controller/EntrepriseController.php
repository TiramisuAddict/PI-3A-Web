<?php

namespace App\Controller;
use App\Entity\Entreprise;
use App\Form\EntrepriseType;
use App\Form\LoginType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

final class EntrepriseController extends AbstractController
{
     #[Route('/compteEntreprise', name: 'compteEntreprise',methods: ['GET', 'POST'])]
    public function creationEntreprise(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $entreprise = new Entreprise();
        $form = $this->createForm(EntrepriseType::class, $entreprise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $logoFile = $form->get('logo')->getData();
            if ($logoFile instanceof UploadedFile) {
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/images/entreprises';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $logoFile->guessExtension();

                $logoFile->move($uploadDir, $newFilename);
                $entreprise->setLogo('/images/entreprises/' . $newFilename);
            }

            $entreprise->setDate_demande(new \DateTime());
            $entreprise->setStatut('en attente');
            $em->persist($entreprise);
            $em->flush();

            
            return $this->redirectToRoute('compteEntreprise');
        }

        return $this->render('entreprise/formulaireEntreprise.html.twig', [
            'form' => $form,
        ]);
    }
}
