<?php

namespace App\Application\Security;

use App\Repository\BankAccessTokenRepository;

final class AccessTokenValidatorService
{
    public function __construct(
        private readonly BankAccessTokenRepository $tokenRepository
    ) {
    }

    public function validate(string $token): array
    {
        $hash = hash('sha256', $token);

        $tokenRow = $this->tokenRepository->findActiveByHash($hash);

        if (!$tokenRow) {
            throw new \RuntimeException('invalid_token');
        }

        // validar expiración
        $now = new \DateTimeImmutable('now');
        $expiresAt = new \DateTimeImmutable($tokenRow['EXPIRES_AT']);

        if ($expiresAt <= $now) {
            throw new \RuntimeException('token_expired');
        }

        return $tokenRow;
    }
}