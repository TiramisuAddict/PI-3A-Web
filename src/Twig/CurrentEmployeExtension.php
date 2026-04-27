<?php

namespace App\Twig;

use App\Entity\Employe;
use App\Repository\EmployeRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CurrentEmployeExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EmployeRepository $employeRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_employe', [$this, 'getCurrentEmploye']),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'current_employe' => $this->getCurrentEmploye(),
        ];
    }

    public function getCurrentEmploye(): ?Employe
    {
        $session = $this->requestStack->getSession();

        if (!$session || $session->get('employe_logged_in') !== true) {
            return null;
        }

        $employeId = $session->get('employe_id');
        if (!$employeId) {
            return null;
        }

        return $this->employeRepository->find($employeId);
    }
}