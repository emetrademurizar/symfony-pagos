<?php

namespace App\Controller\Api\Rest;

use App\Application\Individual\ConsultaIndividualService;
use App\Application\Individual\PagoIndividualService;
use App\Application\Individual\ReversionIndividualService;
use App\Application\Individual\TotalConsultaService;
use App\Application\Individual\TotalPagoService;
use App\Application\Individual\TotalReversionService;
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
            (string) ($data['pass'] ?? ''),
            (string)($request->getClientIp() ?? '')
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
        $ip         = (string)($request->getClientIp() ?? '');
        $result = $service->execute($remisiones, $usuario, $pass, $ip);

        return new JsonResponse($result, isset($result['error']) ? 400 : 200);
    }

    #[Route('/api/rest/individual/reversion', methods: ['POST'])]
    public function reversion(Request $request, ReversionIndividualService $service): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $documento  = (string)($data['documento'] ?? '');
        $usuario    = (string)($data['usuario'] ?? '');
        $pass       = (string)($data['pass'] ?? '');
        $message     = (string)($data['message'] ?? '');
        $ip         = (string)($request->getClientIp() ?? '');

        // Llamar al servicio de reversión
        $result = $service->execute($documento, $usuario, $pass, $message, $ip);

        // Enviar el resultado, dependiendo de si hubo error o no
        return new JsonResponse($result, isset($result['error']) ? 400 : 200);
    }

    #[Route('/api/rest/individual/total', methods: ['POST'])]
    public function totalConsulta(Request $request, TotalConsultaService $service): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        // Obtener los 4 parámetros desde el body
        $tipoPlaca = (string) ($data['tipo_placa'] ?? '');
        $placa     = (string) ($data['placa'] ?? '');
        $usuario   = (string) ($data['usuario'] ?? '');
        $clave     = (string) ($data['pass'] ?? '');
        $ip         = (string)($request->getClientIp() ?? '');

        // Llamar al servicio de total consulta
        $result = $service->execute($tipoPlaca, $placa, $usuario, $clave, $ip);

        // Devolver la respuesta
        return new JsonResponse($result, isset($result['error']) ? 400 : 200);
    }

    #[Route('/api/rest/individual/total-pago', methods: ['POST'])]
    public function totalPago(Request $request, TotalPagoService $service): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $tipoPlaca      = (string)($data['tipo_placa'] ?? '');
        $placa          = (string)($data['placa'] ?? '');
        $total          = $data['total'] ?? 0;
        $noReferencia   = (string)($data['no_referencia'] ?? '');
        $noAutorizacion = (string)($data['no_autorizacion'] ?? '');
        $usuario        = (string)($data['usuario'] ?? '');
        $pass           = (string)($data['pass'] ?? '');
        $ip             = (string)($request->getClientIp() ?? '');

        $result = $service->execute(
            $tipoPlaca,
            $placa,
            $total,
            $noReferencia,
            $noAutorizacion,
            $usuario,
            $pass,
            $ip
        );

        return new JsonResponse($result, isset($result['error']) ? 400 : 200);
    }

}