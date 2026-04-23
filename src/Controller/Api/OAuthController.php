<?php

namespace App\Controller\Api;

use App\Application\Security\OAuthTokenIssueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OAuthController extends AbstractController
{
    #[Route('/oauth/token', methods: ['POST'])]
    public function token(
        Request $request,
        OAuthTokenIssueService $tokenIssueService
    ): JsonResponse {
        if (!$this->isFormUrlEncoded($request)) {
            return $this->jsonError('invalid_request', Response::HTTP_BAD_REQUEST);
        }

        $grantType = (string) $request->request->get('grant_type', '');

        if ($grantType !== 'client_credentials') {
            return $this->jsonError('unsupported_grant_type', Response::HTTP_BAD_REQUEST);
        }

        $authorization = (string) $request->headers->get('Authorization', '');

        if (!str_starts_with($authorization, 'Basic ')) {
            return $this->jsonError('invalid_client', Response::HTTP_UNAUTHORIZED);
        }

        $encoded = trim(substr($authorization, 6));
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return $this->jsonError('invalid_client', Response::HTTP_UNAUTHORIZED);
        }

        [$clientId, $clientSecret] = explode(':', $decoded, 2);

        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);

        if ($clientId === '' || $clientSecret === '') {
            return $this->jsonError('invalid_client', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $tokenIssueService->issue($clientId, $clientSecret);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'invalid_client') {
                return $this->jsonError('invalid_client', Response::HTTP_UNAUTHORIZED);
            }

            return $this->jsonError('internal_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            return $this->jsonError('internal_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = new JsonResponse($result, Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function isFormUrlEncoded(Request $request): bool
    {
        $contentType = (string) $request->headers->get('Content-Type', '');

        return str_contains(strtolower($contentType), 'application/x-www-form-urlencoded');
    }

    private function jsonError(string $error, int $status): JsonResponse
    {
        $response = new JsonResponse(['error' => $error], $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        if ($status === Response::HTTP_UNAUTHORIZED) {
            $response->headers->set('WWW-Authenticate', 'Basic realm="OAuth Token Endpoint"');
        }

        return $response;
    }
}