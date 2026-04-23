<?php

namespace App\Application\Security;

use App\Repository\BankClientCredentialRepository;
use App\Repository\BankClientRepository;
use App\Security\ClientIdGenerator;
use App\Security\ClientSecretGenerator;

final class BankClientProvisionService
{
    public function __construct(
        private readonly BankClientRepository $bankClientRepository,
        private readonly BankClientCredentialRepository $credentialRepository,
        private readonly ClientIdGenerator $clientIdGenerator,
        private readonly ClientSecretGenerator $clientSecretGenerator,
    ) {
    }

    public function create(string $bankCode, string $environment, ?string $label = null): array
    {
        $bankClient = $this->bankClientRepository->findActiveByBankAndEnvironment($bankCode, $environment);

        if (!$bankClient) {
            throw new \RuntimeException('No existe un BANK_CLIENT activo para el banco y ambiente indicados.');
        }

        $bankClientId = (int) $bankClient['ID'];

        $nextNumber = $this->credentialRepository->countByBankClientId($bankClientId) + 1;

        $clientId = $this->clientIdGenerator->generate(
            (string) $bankClient['BANK_CODE'],
            (string) $bankClient['ENVIRONMENT'],
            $nextNumber
        );

        if ($this->credentialRepository->existsClientId($clientId)) {
            throw new \RuntimeException('El client_id generado ya existe. Intenta nuevamente.');
        }

        $clientSecret = $this->clientSecretGenerator->generate();

        $clientSecretHash = password_hash($clientSecret, PASSWORD_BCRYPT);

        if ($clientSecretHash === false) {
            throw new \RuntimeException('No fue posible generar el hash del client_secret.');
        }

        $expiresAt = (new \DateTimeImmutable('+180 days'))->format('Y-m-d H:i:s');

        $this->credentialRepository->insert([
            'bank_client_id' => $bankClientId,
            'client_id' => $clientId,
            'client_secret_hash' => $clientSecretHash,
            'label' => $label,
            'status' => 'ACTIVE',
            'expires_at' => $expiresAt,
        ]);

        return [
            'bank_client_id' => $bankClientId,
            'bank_code' => (string) $bankClient['BANK_CODE'],
            'bank_name' => (string) $bankClient['BANK_NAME'],
            'environment' => (string) $bankClient['ENVIRONMENT'],
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'expires_at' => $expiresAt,
        ];
    }
}