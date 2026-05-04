<?php

namespace App\Controller;

use App\Service\ParticipationTicketService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/participation')]
final class ParticipationTicketController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(default::APP_PUBLIC_URL)%')]
        private readonly ?string $publicAppUrl = null,
    ) {
    }

    #[Route('/ticket/{id_participation}', name: 'app_participation_ticket', methods: ['GET'])]
    public function ticket(
        int $id_participation,
        Request $request,
        ParticipationTicketService $participationTicketService,
        SessionInterface $session
    ): Response {
        $ticket = $participationTicketService->getTicketByParticipationId($id_participation);
        if ($ticket === null) {
            throw $this->createNotFoundException('Participation introuvable.');
        }

        $currentUserId = $this->getUserIdFromSession($session);
        $role = mb_strtolower((string) $session->get('employe_role', ''));
        $isAdmin = $session->get('admin_logged_in') === true || str_contains($role, 'admin');

        if (!$isAdmin && ($currentUserId === null || $currentUserId !== (int) $ticket['utilisateur_id'])) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas consulter ce ticket.');
        }

        $signature = $participationTicketService->generateSignature($ticket);
        $validationPath = $this->buildValidationPath($id_participation, $signature);
        $validationUrl = $this->buildPublicValidationUrl($request, $id_participation, $signature);
        $publicTicketUrl = $this->buildPublicTicketUrl($request, $id_participation, $signature);

        return $this->render('participation/ticket.html.twig', [
            'ticket' => $ticket,
            'ticket_holder' => $participationTicketService->getDisplayName($ticket),
            'validation_path' => $validationPath,
            'validation_url' => $validationUrl,
            'public_ticket_url' => $publicTicketUrl,
        ]);
    }

    #[Route('/public-ticket/{id_participation}/{signature}', name: 'app_participation_public_ticket', methods: ['GET'])]
    public function publicTicket(
        int $id_participation,
        string $signature,
        Request $request,
        ParticipationTicketService $participationTicketService
    ): Response {
        $ticket = $participationTicketService->getTicketByParticipationId($id_participation);
        if ($ticket === null) {
            throw $this->createNotFoundException('Ticket introuvable.');
        }

        if (!$participationTicketService->isSignatureValid($ticket, $signature)) {
            throw $this->createAccessDeniedException('Signature de ticket invalide.');
        }

        return $this->render('participation/ticket.html.twig', [
            'ticket' => $ticket,
            'ticket_holder' => $participationTicketService->getDisplayName($ticket),
            'validation_path' => $this->buildValidationPath($id_participation, $signature),
            'validation_url' => $this->buildPublicValidationUrl($request, $id_participation, $signature),
            'public_ticket_url' => $this->buildPublicTicketUrl($request, $id_participation, $signature),
        ]);
    }

    #[Route('/validate/{id_participation}/{signature}', name: 'app_participation_ticket_validate', methods: ['GET'])]
    public function validateTicket(
        int $id_participation,
        string $signature,
        ParticipationTicketService $participationTicketService
    ): Response {
        $ticket = $participationTicketService->getTicketByParticipationId($id_participation);
        if ($ticket === null) {
            throw $this->createNotFoundException('Ticket introuvable.');
        }

        $isValid = $participationTicketService->isSignatureValid($ticket, $signature);

        return $this->render('participation/validate.html.twig', [
            'ticket' => $ticket,
            'ticket_holder' => $participationTicketService->getDisplayName($ticket),
            'is_valid' => $isValid,
        ]);
    }

    private function getUserIdFromSession(SessionInterface $session): ?int
    {
        foreach (['employe_id', 'employee_id', 'id_employe', 'user_id'] as $key) {
            $userId = $session->get($key);
            if ($userId !== null) {
                return (int) $userId;
            }
        }

        return null;
    }

    private function buildValidationPath(int $participationId, string $signature): string
    {
        return $this->generateUrl('app_participation_ticket_validate', [
            'id_participation' => $participationId,
            'signature' => $signature,
        ], UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    private function buildPublicValidationUrl(Request $request, int $participationId, string $signature): string
    {
        return $this->buildPublicRouteUrl($request, 'app_participation_ticket_validate', [
            'id_participation' => $participationId,
            'signature' => $signature,
        ]);
    }

    private function buildPublicTicketUrl(Request $request, int $participationId, string $signature): string
    {
        return $this->buildPublicRouteUrl($request, 'app_participation_public_ticket', [
            'id_participation' => $participationId,
            'signature' => $signature,
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function buildPublicRouteUrl(Request $request, string $route, array $parameters): string
    {
        $path = $this->generateUrl($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);

        return $this->resolvePublicBaseUrl($request) . $path;
    }

    private function resolvePublicBaseUrl(Request $request): string
    {
        $requestBaseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $requestHost = $request->getHost();

        if (!in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true)) {
            return $requestBaseUrl;
        }

        $networkBaseUrl = $this->buildNetworkBaseUrl($request);
        if ($networkBaseUrl !== null) {
            return $networkBaseUrl;
        }

        $configuredBaseUrl = trim((string) $this->publicAppUrl);

        return $configuredBaseUrl !== '' ? rtrim($configuredBaseUrl, '/') : $requestBaseUrl;
    }

    private function buildNetworkBaseUrl(Request $request): ?string
    {
        $hostName = gethostname();
        if ($hostName === false) {
            return null;
        }

        $addresses = gethostbynamel($hostName);

        if ($addresses === false) {
            return null;
        }

        foreach ($addresses as $address) {
            $isValidIpv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

            if ($isValidIpv4 && !str_starts_with($address, '127.')) {
                $port = $request->getPort();
                $portSuffix = in_array($port, [80, 443], true) ? '' : ':' . $port;

                return $request->getScheme() . '://' . $address . $portSuffix;
            }
        }

        return null;
    }
}
