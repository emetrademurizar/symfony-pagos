<?php

namespace App\Application\Security;

use App\Repository\BankAccessTokenRepository;
use App\Repository\BankClientCredentialRepository;
use App\Security\AccessTokenGenerator;

final class OAuthTokenIssueService
{
    public function __construct(
        private readonly BankClientCredentialRepository $credentialRepository,
        private readonly BankAccessTokenRepository $accessTokenRepository,
        private readonly AccessTokenGenerator $accessTokenGenerator,
    ) {
    }

    public function issue(string $clientId, string $clientSecret): array
    {
        $credential = $this->credentialRepository->findActiveByClientId($clientId);

        if (!$credential) {
            throw new \RuntimeException('invalid_client');
        }

        if (!empty($credential['EXPIRES_AT'])) {
            $expiresAtCredential = new \DateTimeImmutable($credential['EXPIRES_AT']);
            $now = new \DateTimeImmutable('now');

            if ($expiresAtCredential <= $now) {
                throw new \RuntimeException('invalid_client');
            }
        }

        $isValidSecret = password_verify($clientSecret, (string) $credential['CLIENT_SECRET_HASH']);

        if (!$isValidSecret) {
            throw new \RuntimeException('invalid_client');
        }

        $bankClientId = (int) $credential['BANK_CLIENT_ID'];

        $accessToken = $this->accessTokenGenerator->generate();
        $tokenHash = $this->accessTokenGenerator->hash($accessToken);
        $tokenPrefix = $this->accessTokenGenerator->prefix($accessToken);

        $issuedAt = new \DateTimeImmutable('now');
        $expiresAt = $issuedAt->modify('+30 minutes');

        $this->accessTokenRepository->insert([
            'bank_client_id' => $bankClientId,
            'token_hash' => $tokenHash,
            'token_prefix' => $tokenPrefix,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'status' => 'ACTIVE',
        ]);

        $this->credentialRepository->markLastUsed((int) $credential['ID']);

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresAt->getTimestamp() - $issuedAt->getTimestamp(),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];
    }
}