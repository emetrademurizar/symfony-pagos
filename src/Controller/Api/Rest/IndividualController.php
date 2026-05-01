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
use App\Application\Security\BearerTokenAuthenticatorService;
use Psr\Log\LoggerInterface;
use App\Application\Security\RequestSecurityHeadersValidator;
use App\Application\Security\ReplayGuardService;
use App\Application\Security\BankRateLimiterService;
use App\Application\Security\BankRequestLoggerService;

class IndividualController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}
    
    #[Route('/api/rest/individual/consulta', methods: ['POST'])]
    public function consulta(Request $request, ConsultaIndividualService $service,
        BearerTokenAuthenticatorService $bearerAuthenticator,        
        RequestSecurityHeadersValidator $headersValidator,
        ReplayGuardService $replayGuardService,        
        BankRateLimiterService $rateLimiterService,
        BankRequestLoggerService $bankRequestLogger): JsonResponse
    {
        try{
            $authenticatedClient = $bearerAuthenticator->authenticate($request);
        } catch(\RuntimeException $e){
            $bankRequestLogger->logRejectedRequest(
                null,
                $request->headers->get('X-Request-Id'),
                'consulta',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                401,
                'FAILED',
                null,
                null,
                'TOKEN INVALIDO O AUSENTE: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '401',
                    'mensaje' => 'TOKEN INVALIDO O AUSENTE'
                ]
            ], 401);
        }

        try {
            $securityHeaders = $headersValidator->validateHeaders($request, 'application/json', 300);
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $request->headers->get('X-Request-Id'),
                'consulta',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                400,
                'OK',
                null,
                null,
                'HEADERS INVALIDOS: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '400',
                    'mensaje' => 'HEADERS DE SEGURIDAD INVALIDOS: ' . $e->getMessage()
                ]
            ], 400);
        }

        $requestId = $securityHeaders['request_id'];
        $requestTimestamp = $securityHeaders['timestamp'];

        try {
            $replayGuardService->validateAndRegister(
                $authenticatedClient->bankClientId,
                $requestId,
                900
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'consulta',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                409,
                'OK',
                'FAILED',
                null,
                'REQUEST_ID REPETIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '409',
                    'mensaje' => 'REQUEST_ID REPETIDO'
                ]
            ], 409);
        }

        try {
            $rateLimiterService->validate(
                $authenticatedClient->bankClientId,
                $authenticatedClient->rateLimitPerMin
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'consulta',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                429,
                'OK',
                'OK',
                'FAILED',
                'LIMITE DE CONSUMO EXCEDIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '429',
                    'mensaje' => 'LIMITE DE CONSUMO EXCEDIDO'
                ]
            ], 429);
        }

        $logId = $bankRequestLogger->logRequest(
            $authenticatedClient->bankClientId,
            $requestId,
            'consulta',
            $securityHeaders['timestamp'],
            (string) ($request->getClientIp() ?? ''),
            $request->getContent()
        );

        $data = json_decode($request->getContent(), true) ?? [];

        $subject = (string) $authenticatedClient->bankClientId;

        $result = $service->execute(
            (string) ($data['tipo_placa'] ?? ''),
            (string) ($data['placa'] ?? ''),
            (string) ($subject), // (string) ($data['usuario'] ?? ''),
            // (string) ($data['pass'] ?? ''),
            (string)($request->getClientIp() ?? '')
        );

        $httpStatus = isset($result['error']) ? 400 : 200;

        $functionalCode = $result['error']['cod'] ?? '000';

        $response = new JsonResponse($result, $httpStatus);

        $bankRequestLogger->closeRequest(
            $logId,
            $httpStatus,
            $functionalCode,
            json_encode($result)
        );

        return $response;
    }

    #[Route('/api/rest/individual/pago', methods: ['POST'])]
    public function pago(Request $request, PagoIndividualService $service,
        BearerTokenAuthenticatorService $bearerAuthenticator,
        RequestSecurityHeadersValidator $headersValidator,
        ReplayGuardService $replayGuardService,
        BankRateLimiterService $rateLimiterService,
        BankRequestLoggerService $bankRequestLogger
    ): JsonResponse
    {
        try{
            $authenticatedClient = $bearerAuthenticator->authenticate($request);
        } catch(\RuntimeException $e){
            $bankRequestLogger->logRejectedRequest(
                null,
                $request->headers->get('X-Request-Id'),
                'pago',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                401,
                'FAILED',
                null,
                null,
                'TOKEN INVALIDO O AUSENTE: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '401',
                    'mensaje' => 'TOKEN INVALIDO O AUSENTE'
                ]
            ], 401);
        }

        try {
            $securityHeaders = $headersValidator->validateHeaders($request, 'application/json', 300);
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $request->headers->get('X-Request-Id'),
                'pago',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                400,
                'OK',
                null,
                null,
                'HEADERS INVALIDOS: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '400',
                    'mensaje' => 'HEADERS DE SEGURIDAD INVALIDOS: ' . $e->getMessage()
                ]
            ], 400);
        }

        $requestId = $securityHeaders['request_id'];
        $requestTimestamp = $securityHeaders['timestamp'];

        try {
            $replayGuardService->validateAndRegister(
                $authenticatedClient->bankClientId,
                $requestId,
                900
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'pago',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                409,
                'OK',
                'FAILED',
                null,
                'REQUEST_ID REPETIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '409',
                    'mensaje' => 'REQUEST_ID REPETIDO'
                ]
            ], 409);
        }

        try {
            $rateLimiterService->validate(
                $authenticatedClient->bankClientId,
                $authenticatedClient->rateLimitPerMin
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'pago',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                429,
                'OK',
                'OK',
                'FAILED',
                'LIMITE DE CONSUMO EXCEDIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '429',
                    'mensaje' => 'LIMITE DE CONSUMO EXCEDIDO'
                ]
            ], 429);
        }

        $logId = $bankRequestLogger->logRequest(
            $authenticatedClient->bankClientId,
            $requestId,
            'pago',
            $securityHeaders['timestamp'],
            (string) ($request->getClientIp() ?? ''),
            $request->getContent()
        );

        $data = json_decode($request->getContent(), true) ?? [];

        $subject = (string) $authenticatedClient->bankClientId;

        $remisiones = is_array($data['remisiones'] ?? null) ? $data['remisiones'] : [];
        $ip         = (string)($request->getClientIp() ?? '');
        $result = $service->execute($remisiones, $subject, $ip);

        $httpStatus = isset($result['error']) ? 400 : 200;

        $functionalCode = $result['error']['cod'] ?? '000';

        $response = new JsonResponse($result, $httpStatus);

        $bankRequestLogger->closeRequest(
            $logId,
            $httpStatus,
            $functionalCode,
            json_encode($result)
        );

        return $response;
    }

    #[Route('/api/rest/individual/reversion', methods: ['POST'])]
    public function reversion(Request $request, ReversionIndividualService $service, 
        BearerTokenAuthenticatorService $bearerAuthenticator,
        RequestSecurityHeadersValidator $headersValidator,
        ReplayGuardService $replayGuardService,
        BankRateLimiterService $rateLimiterService,
        BankRequestLoggerService $bankRequestLogger
    ): JsonResponse
    {   
        try{
            $authenticatedClient = $bearerAuthenticator->authenticate($request);
        } catch(\RuntimeException $e){
            $bankRequestLogger->logRejectedRequest(
                null,
                $request->headers->get('X-Request-Id'),
                'reversion',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                401,
                'FAILED',
                null,
                null,
                'TOKEN INVALIDO O AUSENTE: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '401',
                    'mensaje' => 'TOKEN INVALIDO O AUSENTE'
                ]
            ], 401);
        }

        try {
            $securityHeaders = $headersValidator->validateHeaders($request, 'application/json', 300);
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $request->headers->get('X-Request-Id'),
                'reversion',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                400,
                'OK',
                null,
                null,
                'HEADERS INVALIDOS: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '400',
                    'mensaje' => 'HEADERS DE SEGURIDAD INVALIDOS: ' . $e->getMessage()
                ]
            ], 400);
        }

        $requestId = $securityHeaders['request_id'];
        $requestTimestamp = $securityHeaders['timestamp'];

        try {
            $replayGuardService->validateAndRegister(
                $authenticatedClient->bankClientId,
                $requestId,
                900
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'reversion',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                409,
                'OK',
                'FAILED',
                null,
                'REQUEST_ID REPETIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '409',
                    'mensaje' => 'REQUEST_ID REPETIDO'
                ]
            ], 409);
        }

        try {
            $rateLimiterService->validate(
                $authenticatedClient->bankClientId,
                $authenticatedClient->rateLimitPerMin
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'reversion',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                429,
                'OK',
                'OK',
                'FAILED',
                'LIMITE DE CONSUMO EXCEDIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '429',
                    'mensaje' => 'LIMITE DE CONSUMO EXCEDIDO'
                ]
            ], 429);
        }

        $logId = $bankRequestLogger->logRequest(
            $authenticatedClient->bankClientId,
            $requestId,
            'reversion',
            $securityHeaders['timestamp'],
            (string) ($request->getClientIp() ?? ''),
            $request->getContent()
        );

        $data = json_decode($request->getContent(), true) ?? [];

        $subject = (string) $authenticatedClient->bankClientId;

        $documento  = (string)($data['documento'] ?? '');
        $message     = (string)($data['message'] ?? '');
        $ip         = (string)($request->getClientIp() ?? '');

        // Llamar al servicio de reversión
        $result = $service->execute($documento, $subject, $message, $ip);

        // Enviar el resultado, dependiendo de si hubo error o no
        $httpStatus = isset($result['error']) ? 400 : 200;

        $functionalCode = $result['error']['cod'] ?? '000';

        $response = new JsonResponse($result, $httpStatus);

        $bankRequestLogger->closeRequest(
            $logId,
            $httpStatus,
            $functionalCode,
            json_encode($result)
        );

        return $response;
    }

    #[Route('/api/rest/individual/total', methods: ['POST'])]
    public function totalConsulta(Request $request, TotalConsultaService $service, 
        BearerTokenAuthenticatorService $bearerAuthenticator,
        RequestSecurityHeadersValidator $headersValidator,
        ReplayGuardService $replayGuardService,
        BankRateLimiterService $rateLimiterService,
        BankRequestLoggerService $bankRequestLogger
    ): JsonResponse
    {

        try{
            $authenticatedClient = $bearerAuthenticator->authenticate($request);
        } catch(\RuntimeException $e){
            $bankRequestLogger->logRejectedRequest(
                null,
                $request->headers->get('X-Request-Id'),
                'consulta total',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                401,
                'FAILED',
                null,
                null,
                'TOKEN INVALIDO O AUSENTE: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '401',
                    'mensaje' => 'TOKEN INVALIDO O AUSENTE'
                ]
            ], 401);
        }

        try {
            $securityHeaders = $headersValidator->validateHeaders($request, 'application/json', 300);
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $request->headers->get('X-Request-Id'),
                'consulta total',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                400,
                'OK',
                null,
                null,
                'HEADERS INVALIDOS: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '400',
                    'mensaje' => 'HEADERS DE SEGURIDAD INVALIDOS: ' . $e->getMessage()
                ]
            ], 400);
        }

        $requestId = $securityHeaders['request_id'];
        $requestTimestamp = $securityHeaders['timestamp'];

        try {
            $replayGuardService->validateAndRegister(
                $authenticatedClient->bankClientId,
                $requestId,
                900
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'consulta total',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                409,
                'OK',
                'FAILED',
                null,
                'REQUEST_ID REPETIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '409',
                    'mensaje' => 'REQUEST_ID REPETIDO'
                ]
            ], 409);
        }

        try {
            $rateLimiterService->validate(
                $authenticatedClient->bankClientId,
                $authenticatedClient->rateLimitPerMin
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'consulta total',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                429,
                'OK',
                'OK',
                'FAILED',
                'LIMITE DE CONSUMO EXCEDIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '429',
                    'mensaje' => 'LIMITE DE CONSUMO EXCEDIDO'
                ]
            ], 429);
        }

        $logId = $bankRequestLogger->logRequest(
            $authenticatedClient->bankClientId,
            $requestId,
            'consulta total',
            $securityHeaders['timestamp'],
            (string) ($request->getClientIp() ?? ''),
            $request->getContent()
        );

        $data = json_decode($request->getContent(), true) ?? [];

        $subject = (string) $authenticatedClient->bankClientId;

        // Obtener los 4 parámetros desde el body
        $tipoPlaca = (string) ($data['tipo_placa'] ?? '');
        $placa     = (string) ($data['placa'] ?? '');
        $ip         = (string)($request->getClientIp() ?? '');

        // Llamar al servicio de total consulta
        $result = $service->execute($tipoPlaca, $placa, $subject, $ip);

        // Devolver la respuesta
        $httpStatus = isset($result['error']) ? 400 : 200;

        $functionalCode = $result['error']['cod'] ?? '000';

        $response = new JsonResponse($result, $httpStatus);

        $bankRequestLogger->closeRequest(
            $logId,
            $httpStatus,
            $functionalCode,
            json_encode($result)
        );

        return $response;
    }

    #[Route('/api/rest/individual/total-pago', methods: ['POST'])]
    public function totalPago(Request $request, TotalPagoService $service, 
        BearerTokenAuthenticatorService $bearerAuthenticator,
        RequestSecurityHeadersValidator $headersValidator,
        ReplayGuardService $replayGuardService,
        BankRateLimiterService $rateLimiterService,
        BankRequestLoggerService $bankRequestLogger
    ): JsonResponse
    {
        try{
            $authenticatedClient = $bearerAuthenticator->authenticate($request);
        } catch(\RuntimeException $e){
            $bankRequestLogger->logRejectedRequest(
                null,
                $request->headers->get('X-Request-Id'),
                'pago total',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                401,
                'FAILED',
                null,
                null,
                'TOKEN INVALIDO O AUSENTE: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '401',
                    'mensaje' => 'TOKEN INVALIDO O AUSENTE'
                ]
            ], 401);
        }

        try {
            $securityHeaders = $headersValidator->validateHeaders($request, 'application/json', 300);
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $request->headers->get('X-Request-Id'),
                'pago total',
                $request->headers->get('X-Timestamp'),
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                400,
                'OK',
                null,
                null,
                'HEADERS INVALIDOS: ' . $e->getMessage()
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '400',
                    'mensaje' => 'HEADERS DE SEGURIDAD INVALIDOS: ' . $e->getMessage()
                ]
            ], 400);
        }

        $requestId = $securityHeaders['request_id'];
        $requestTimestamp = $securityHeaders['timestamp'];

        try {
            $replayGuardService->validateAndRegister(
                $authenticatedClient->bankClientId,
                $requestId,
                900
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'pago total',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                409,
                'OK',
                'FAILED',
                null,
                'REQUEST_ID REPETIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '409',
                    'mensaje' => 'REQUEST_ID REPETIDO'
                ]
            ], 409);
        }

        try {
            $rateLimiterService->validate(
                $authenticatedClient->bankClientId,
                $authenticatedClient->rateLimitPerMin
            );
        } catch (\RuntimeException $e) {
            $bankRequestLogger->logRejectedRequest(
                $authenticatedClient->bankClientId,
                $requestId,
                'pago total',
                $requestTimestamp,
                (string) ($request->getClientIp() ?? ''),
                $request->getContent(),
                429,
                'OK',
                'OK',
                'FAILED',
                'LIMITE DE CONSUMO EXCEDIDO'
            );
            return new JsonResponse([
                'error' => [
                    'cod' => '429',
                    'mensaje' => 'LIMITE DE CONSUMO EXCEDIDO'
                ]
            ], 429);
        }

        $logId = $bankRequestLogger->logRequest(
            $authenticatedClient->bankClientId,
            $requestId,
            'pago total',
            $securityHeaders['timestamp'],
            (string) ($request->getClientIp() ?? ''),
            $request->getContent()
        );

        $data = json_decode($request->getContent(), true) ?? [];

        $subject = (string) $authenticatedClient->bankClientId;

        $tipoPlaca      = (string)($data['tipo_placa'] ?? '');
        $placa          = (string)($data['placa'] ?? '');
        $total          = $data['total'] ?? 0;
        $noReferencia   = (string)($data['no_referencia'] ?? '');
        $noAutorizacion = (string)($data['no_autorizacion'] ?? '');
        $ip             = (string)($request->getClientIp() ?? '');

        $result = $service->execute(
            $tipoPlaca,
            $placa,
            $total,
            $noReferencia,
            $noAutorizacion,
            $subject,
            // $pass,
            $ip
        );

        $httpStatus = isset($result['error']) ? 400 : 200;

        $functionalCode = $result['error']['cod'] ?? '000';

        $response = new JsonResponse($result, $httpStatus);

        $bankRequestLogger->closeRequest(
            $logId,
            $httpStatus,
            $functionalCode,
            json_encode($result)
        );

        return $response;
    }

}