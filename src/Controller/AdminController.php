<?php

namespace App\Controller;

use App\Entity\AdministrateurSysteme;
use App\Form\LoginType;
use App\Entity\Entreprise;
use App\Entity\Employe; 
use App\Entity\Compte;
use App\Services\MailerService;
use App\Services\PasswordGenerator;
use App\Repository\AdministrateurSystemeRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use CMEN\GoogleChartsBundle\GoogleCharts\Charts\LineChart;
use CMEN\GoogleChartsBundle\GoogleCharts\Charts\BarChart;
use CMEN\GoogleChartsBundle\GoogleCharts\Charts\PieChart;

final class AdminController extends AbstractController
{   
    #[Route('/admin/home', name: 'admin_home', methods: ['GET'])]
    public function home(Request $request, SessionInterface $session, EntrepriseRepository $entrepriseRepo): Response
    {
        if ($session->get('admin_logged_in') !== true) {
            return $this->redirectToRoute('login');
        }

        $allowedStatuses = ['acceptée', 'en attente', 'refusée'];
        $statusFilter = $request->query->get('status', '');
        if (!in_array($statusFilter, $allowedStatuses, true)) {
            $statusFilter = '';
        }

        $allRequests = $statusFilter === ''
            ? $entrepriseRepo->findAll()
            : $entrepriseRepo->findByFilters('', $statusFilter);

        $totalRequests = count($allRequests);
        $acceptedRequests = 0;
        $refusedRequests = 0;
        $pendingRequests = 0;

        foreach ($allRequests as $requestItem) {
            if ($requestItem->getStatut() === 'acceptée') {
                ++$acceptedRequests;
            } elseif ($requestItem->getStatut() === 'refusée') {
                ++$refusedRequests;
            } elseif ($requestItem->getStatut() === 'en attente') {
                ++$pendingRequests;
            }
        }

        $lineRows = [['Date', 'Demandes']];
        $countByDate = [];
        foreach ($allRequests as $requestItem) {
            $date = $requestItem->getDate_demande();
            if (!$date instanceof \DateTimeInterface) {
                continue;
            }

            $key = $date->format('Y-m-d');
            if (!isset($countByDate[$key])) {
                $countByDate[$key] = 0;
            }
            ++$countByDate[$key];
        }
        ksort($countByDate);

        foreach ($countByDate as $dateKey => $totalByDate) {
            $lineRows[] = [(new \DateTimeImmutable($dateKey))->format('d/m/Y'), $totalByDate];
        }
        if (count($lineRows) === 1) {
            $lineRows[] = ['Aucune donnée', 0];
        }

        $lineChart = new LineChart();
        $lineChart->getData()->setArrayToDataTable($lineRows);
        $lineChart->getOptions()->setHeight(340);
        $lineChart->getOptions()->setCurveType('function');
        $lineChart->getOptions()->getLegend()->setPosition('none');
        $lineChart->getOptions()->getHAxis()->setTitle('Date de demande');
        $lineChart->getOptions()->getVAxis()->setTitle('Nombre de demandes');
        $lineChart->getOptions()->getVAxis()->setMinValue(0);
        $lineChart->getOptions()->setColors(['#4254D6']);

        $statusColorMap = [
            'acceptée' => '#2FB344',
            'en attente' => '#F59F00',
            'refusée' => '#D63939',
        ];

        $barRows = [['Statut', 'Demandes', ['role' => 'style']]];
        $statusCount = [
            'acceptée' => 0,
            'en attente' => 0,
            'refusée' => 0,
        ];

        foreach ($allRequests as $requestItem) {
            $status = (string) $requestItem->getStatut();
            if (isset($statusCount[$status])) {
                ++$statusCount[$status];
            }
        }

        foreach ($statusCount as $status => $totalByStatus) {
            if ($statusFilter !== '' && $status !== $statusFilter) {
                continue;
            }
            $barRows[] = [
                $status,
                $totalByStatus,
                $statusColorMap[$status] ?? '#4254D6',
            ];
        }
        if (count($barRows) === 1) {
            $barRows[] = ['Aucune donnée', 0, '#4254D6'];
        }

        $barChart = new BarChart();
        $barChart->getData()->setArrayToDataTable($barRows);
        $barChart->getOptions()->setHeight(340);
        $barChart->getOptions()->getLegend()->setPosition('none');
        $barChart->getOptions()->getHAxis()->setTitle('Nombre de demandes');
        $barChart->getOptions()->getVAxis()->setTitle('Statut');

        $pieRows = [['Pays', 'Demandes']];
        $countryCount = [];
        foreach ($allRequests as $requestItem) {
            $country = trim((string) $requestItem->getPays());
            if ($country === '') {
                $country = 'Inconnu';
            }

            if (!isset($countryCount[$country])) {
                $countryCount[$country] = 0;
            }
            ++$countryCount[$country];
        }

        arsort($countryCount);
        $countryCount = array_slice($countryCount, 0, 5, true);
        foreach ($countryCount as $country => $totalByCountry) {
            $pieRows[] = [$country, $totalByCountry];
        }
        if (count($pieRows) === 1) {
            $pieRows[] = ['Aucune donnée', 0];
        }

        $pieChart = new PieChart();
        $pieChart->getData()->setArrayToDataTable($pieRows);
        $pieChart->getOptions()->setHeight(360);
        $pieChart->getOptions()->setPieHole(0.35);
        $pieChart->getOptions()->getLegend()->setPosition('right');
        $pieChart->getOptions()->setColors(['#4254D6', '#2FB344', '#F59F00', '#D63939', '#1F2937']);

        return $this->render('admin/home.html.twig', [
            'total_requests' => $totalRequests,
            'accepted_requests' => $acceptedRequests,
            'refused_requests' => $refusedRequests,
            'pending_requests' => $pendingRequests,
            'current_status' => $statusFilter,
            'line_chart' => $lineChart,
            'bar_chart' => $barChart,
            'pie_chart' => $pieChart,
        ]);
    }

    #[Route('/admin/systeme/demandes', name: 'admin_dashboard', methods: ['GET', 'POST'])]
    public function dashboard(Request $request, EntrepriseRepository $entrepriseRepo, EntityManagerInterface $em, SessionInterface $session, PasswordGenerator $passwordGenerator, MailerInterface $mailer, MailerService $mailerService, UserPasswordHasherInterface $passwordHasher, PaginatorInterface $paginator): Response
    {
        if ($session->get('admin_logged_in') !== true) {
            return $this->redirectToRoute('login');
        }
        $search = $request->query->get('search', '');
        $status = $request->query->get('status', '');

        $allFilteredEntreprises = $entrepriseRepo->findByFilters($search, $status);
        $entreprises = $paginator->paginate(
            $entrepriseRepo->createByFiltersQueryBuilder($search, $status),
            max(1,  $request->query->get('page', 1)),
            6
        );

        if ($request->isMethod('POST')) {
            $id = $request->request->get('id_entreprise');
            $action = $request->request->get('action');

            $entreprise = $entrepriseRepo->find($id);
            if ($entreprise) {
               if ($action === 'accepter') {
                    $recipientEmail = $entreprise->getEmail();
                    $entreprise->setStatut('acceptée');
                    $employe = new Employe();
                    $employe->setNom($entreprise->getNom());
                    $employe->setPrenom($entreprise->getPrenom());
                    $employe->setTelephone($entreprise->getTelephone());
                    $employe->setEmail($recipientEmail);
                    $employe->setRole('administrateur entreprise');
                    $employe->setPoste('CEO');
                    $employe->setEntreprise($entreprise);
                    $em->persist($employe);

                    $plainPassword = $passwordGenerator->generatePlain();
                    $compte = new Compte();
                    $compte->setMot_de_passe($passwordHasher->hashPassword($compte, $plainPassword));
                    $compte->setEmploye($employe);
                    $em->persist($compte);

                    $em->flush();

                    try {
                        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                            throw new \InvalidArgumentException('Adresse e-mail invalide: ' . $recipientEmail);
                        }

                        $mailerService->sendTemporaryPassword(
                            $mailer,
                            $recipientEmail,
                            (string) $employe->getPrenom(),
                            (string) $employe->getNom(),
                            $plainPassword
                        );
                        $this->addFlash('success', 'Compte cree et mot de passe envoye par e-mail.');
                    } catch (\Throwable $exception) {
                        $this->addFlash('warning', 'Compte cree, mais l\'envoi de l\'e-mail a echoue: ' . $exception->getMessage());
                    }
                } elseif ($action === 'refuser') {
                    $entreprise->setStatut('refusée');
                    $em->flush();
                }
            }

            return $this->redirectToRoute('admin_dashboard');
        }

        $accepte = 0;
        $refuse = 0;
        $attente = 0;
        foreach ($allFilteredEntreprises as $entrepriseItem) {
            if ($entrepriseItem->getStatut() === 'acceptée') {
                $accepte++;
            }
            if ($entrepriseItem->getStatut() === 'refusée') {
                $refuse++;
            }
            if ($entrepriseItem->getStatut() === 'en attente') {
                $attente++;
            }
        }

            return $this->render('admin/admin.html.twig', [
            'entreprises' => $entreprises,
            'stats_total' => count($allFilteredEntreprises),
            'stats_accepte' => $accepte,
            'stats_refuse' => $refuse,
            'stats_attente' => $attente,
        ]);
    }
}