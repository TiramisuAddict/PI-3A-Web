<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/map')]
class MapController extends AbstractController
{
    private HttpClientInterface $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    #[Route('/search', name: 'map_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));

        if ($query === '') {
            return new JsonResponse([]);
        }

        try {
            $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 5,
                    'accept-language' => 'fr',
                    'addressdetails' => 1,
                ],
                'headers' => [
                    'User-Agent' => 'MomentumWebApp/1.0',
                ],
            ]);

            return new JsonResponse($response->toArray(false));
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/reverse', name: 'map_reverse', methods: ['GET'])]
    public function reverse(Request $request): JsonResponse
    {
        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');

        if ($lat === null || $lon === null) {
            return new JsonResponse([
                'error' => true,
                'message' => 'lat et lon sont requis'
            ], 400);
        }

        try {
            $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/reverse', [
                'query' => [
                    'lat' => $lat,
                    'lon' => $lon,
                    'format' => 'json',
                    'accept-language' => 'fr',
                    'addressdetails' => 1,
                ],
                'headers' => [
                    'User-Agent' => 'MomentumWebApp/1.0',
                ],
            ]);

            return new JsonResponse($response->toArray(false));
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}