<?php

namespace App\Application\Security;

final class AuthenticatedBankClient
{
    public function __construct(
        public readonly int $bankClientId,
        public readonly int $tokenId,
        public readonly string $tokenPrefix,
        public readonly string $bankCode,
        public readonly string $bankName,
        public readonly string $environment,
        public readonly string $caja,
        public readonly int $rateLimitPerMin
    ) {
    }
}