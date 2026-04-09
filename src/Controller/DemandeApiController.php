<?php

namespace App\Controller;

use App\Form\DemandeDetailType;
use App\Services\DemandeFormHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DemandeApiController extends AbstractController
{
    private DemandeFormHelper $formHelper;

    public function __construct(DemandeFormHelper $formHelper)
    {
        $this->formHelper = $formHelper;
    }

    #[Route('/demande-api/fields/{type}', name: 'demande_api_fields', methods: ['GET'])]
    public function getFields(string $type): JsonResponse
    {
        $fields = $this->formHelper->getFieldsForType($type);
        return new JsonResponse($fields);
    }

    #[Route('/demande-api/types/{categorie}', name: 'demande_api_types', methods: ['GET'])]
    public function getTypesForCategory(string $categorie): JsonResponse
    {
        $types = $this->formHelper->getTypesForCategory($categorie);
        return new JsonResponse($types);
    }

    #[Route('/demande-api/detail-form/{type}', name: 'demande_api_detail_form', methods: ['GET'])]
    public function getDetailForm(string $type): Response
    {
        $fields = $this->formHelper->getFieldsForType($type);

        if (empty($fields)) {
            return new Response('', Response::HTTP_OK);
        }

        $detailForm = $this->createForm(DemandeDetailType::class, null, [
            'fields' => $fields,
            'existing_details' => [],
        ]);

        return $this->render('demande/_detail_fields.html.twig', [
            'detailForm' => $detailForm->createView(),
            'fields' => $fields,
        ]);
    }
}