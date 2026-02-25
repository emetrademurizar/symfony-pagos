<?php

namespace App\Controller\Api\Rest;

use App\Application\Individual\ConsultaIndividualService;
use App\Application\Individual\PagoIndividualService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class IndividualController extends AbstractController
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

        return $this->json($result);
    }

    #[Route('/api/rest/individual/pago', methods: ['POST'])]
    public function pago(Request $request, PagoIndividualService $service): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $remisiones = is_array($data['remisiones'] ?? null) ? $data['remisiones'] : [];
        $usuario    = (string)($data['usuario'] ?? '');
        $pass       = (string)($data['pass'] ?? '');

        $result = $service->execute($remisiones, $usuario, $pass);

        return new JsonResponse($result, isset($result['error']) ? 400 : 200);
    }
}