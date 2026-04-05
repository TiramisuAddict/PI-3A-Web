<?php

namespace App\Controller;

use App\Repository\EmployéRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auth')]
final class AuthController extends AbstractController
{
    #[Route('/login-id', name: 'app_auth_login_id', methods: ['GET', 'POST'])]
    public function loginById(Request $request, EmployéRepository $employeRepository): Response
    {
        $errorMessage = null;
        $inputValue = trim((string) $request->request->get('employee_id', ''));
        $currentEmploye = null;

        if ($request->hasSession()) {
            $existingId = $request->getSession()->get('employee_id');
            if (is_int($existingId) || ctype_digit((string) $existingId)) {
                $currentEmploye = $employeRepository->find((int) $existingId);
            }
        }

        if ($request->isMethod('POST')) {
            if (!ctype_digit($inputValue)) {
                $errorMessage = 'Veuillez saisir un ID employe valide.';
            } else {
                $employe = $employeRepository->find((int) $inputValue);

                if ($employe === null) {
                    $errorMessage = 'Aucun employe trouve avec cet ID.';
                } else {
                    $request->getSession()->set('employee_id', $employe->getIdEmploye());

                    return $this->redirectToRoute('app_projet_index');
                }
            }
        }

        return $this->render('auth/login_id.html.twig', [
            'errorMessage' => $errorMessage,
            'currentEmploye' => $currentEmploye,
            'canManageProjects' => false,
            'canManageTasks' => false,
        ]);
    }

    #[Route('/logout', name: 'app_auth_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->remove('employee_id');
        }

        return $this->redirectToRoute('app_auth_login_id');
    }
}
