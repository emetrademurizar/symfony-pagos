<?php

namespace App\Application\Security;

use App\Repository\BankRequestReplayGuardRepository;

final class ReplayGuardService
{
    public function __construct(
        private readonly BankRequestReplayGuardRepository $repository
    ) {}

    public function validateAndRegister(
        int $bankClientId,
        string $requestId,
        int $windowSeconds = 900
    ): void {
        if ($this->repository->existsActive($bankClientId, $requestId)) {
            throw new \RuntimeException('replay_detected');
        }

        $this->repository->insert($bankClientId, $requestId, $windowSeconds);
    }
}