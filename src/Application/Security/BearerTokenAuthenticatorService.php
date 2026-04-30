<?php

namespace App\Application\Security;

use App\Repository\BankAccessTokenRepository;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

final class BearerTokenAuthenticatorService
{
    public function __construct(
        private readonly BankAccessTokenRepository $tokenRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function authenticate(Request $request): AuthenticatedBankClient
    {
        $authHeader = (string) $request->headers->get('Authorization', '');

        $this->logger->info('HEADER', [
            'authHeader'    => $authHeader,
        ]);

        if (!str_starts_with($authHeader, 'Bearer ')) {
            throw new \RuntimeException('missing_bearer_token');
        }

        $token = trim(substr($authHeader, 7));

        if ($token === '') {
            throw new \RuntimeException('empty_bearer_token');
        }

        $tokenHash = hash('sha256', $token);

        

        $row = $this->tokenRepository->findActiveByHash($tokenHash);

        if (!$row) {
            throw new \RuntimeException('invalid_token');
        }

        $tz = new \DateTimeZone('America/Guatemala');

        $expiresAt = new \DateTimeImmutable((string) $row['EXPIRES_AT'], $tz);
        $now = new \DateTimeImmutable('now', $tz);
        $this->logger->info('Authenticating request with bearer token', [
            'token' => $token,
            'tokenHash' => $tokenHash,
            'expires' => $expiresAt,
            'now' => $now
        ]);
        if ($expiresAt <= $now) {
            throw new \RuntimeException('expired_token');
        }

        return new AuthenticatedBankClient(
            bankClientId: (int) $row['BANK_CLIENT_ID'],
            tokenId: (int) $row['TOKEN_ID'],
            tokenPrefix: (string) $row['TOKEN_PREFIX'],
            bankCode: (string) $row['BANK_CODE'],
            bankName: (string) $row['BANK_NAME'],
            environment: (string) $row['ENVIRONMENT'],
            caja: (string) $row['CAJA'],
            rateLimitPerMin: (int) $row['RATE_LIMIT_PER_MIN']
        );
    }
}