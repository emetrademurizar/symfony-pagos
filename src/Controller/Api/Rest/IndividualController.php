<?php

namespace App\Controller\Api\Rest;

use App\Application\Individual\ConsultaIndividualService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class IndividualController
{
    #[Route('/api/rest/individual/consulta', methods: ['POST'])]
    public function consulta(Request $request, ConsultaIndividualService $service): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $result = $service->execute(
            (string) ($data['tipo_placa'] ?? ''),
            (string) ($data['placa'] ?? ''),
            (string) ($data['usuario'] ?? ''),
            (string) ($data['pass'] ?? '')
        );

        return new JsonResponse($result);
    }
}
