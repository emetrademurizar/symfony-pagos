<?php

namespace App\Utils;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

class JwtHelper
{
    public function __construct(
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly LoggerInterface $logger
    ) {}

    public function getTokenFromRequest(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');

        if (!$authHeader) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    public function decodeToken(string $token): array|false
    {
        try {
            return $this->jwtEncoder->decode($token);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getSubjectFromRequest(Request $request): string|false
    {
        $token = $this->getTokenFromRequest($request);

        

        if (!$token) {
            return false;
        }

        $payload = $this->decodeToken($token);
        
        $this->logger->info('token', [
            'token'    => $token,
            'payload'  => $payload
        ]);

        if ($payload === false) {
            return false;
        }

        return (string)($payload['username'] ?? $payload['sub'] ?? false);
    }
}