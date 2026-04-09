<?php

namespace App\Controller\Api;

use App\Security\JwtClientUser;
use App\Utils\Validator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    #[Route('/api/auth/token', methods: ['POST'])]
    public function token(
        Request $request,
        Validator $validator,
        JWTTokenManagerInterface $jwtManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        $clientId = (string)($data['client_id'] ?? '');
        $clientSecret = (string)($data['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            return new JsonResponse([
                'error' => 'client_id y client_secret son requeridos'
            ], 400);
        }

        $usuarioValido = $validator->validUser($clientId, $clientSecret);

        if ($usuarioValido === false) {
            return new JsonResponse([
                'error' => 'Credenciales inválidas'
            ], 401);
        }

        $user = new JwtClientUser($clientId);

        $tz = new \DateTimeZone('America/Guatemala');
        $now = new \DateTimeImmutable('now', $tz);
        $endOfDay = $now->setTime(23, 59, 59);

        $payload = [
            'sub' => $clientId,
            'exp' => $endOfDay->getTimestamp(),
        ];

        $token = $jwtManager->createFromPayload($user, $payload);

        return new JsonResponse([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $endOfDay->getTimestamp() - $now->getTimestamp(),
            'expires_at' => $endOfDay->format('Y-m-d H:i:s'),
        ]);
    }


    #[Route('/api/auth/me', methods: ['GET'])]
    public function me(Request $request, \App\Utils\JwtHelper $jwtHelper): JsonResponse
    {
        $subject = $jwtHelper->getSubjectFromRequest($request);

        if ($subject === false) {
            return new JsonResponse(['error' => 'Token inválido o ausente'], 401);
        }

        return new JsonResponse([
            'subject' => $subject
        ]);
    }
}