<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class BankRequestReplayGuardRepository
{
    public function __construct(
        private readonly Connection $conn
    ) {}

    public function existsActive(int $bankClientId, string $requestId): bool
    {
        $sql = <<<SQL
            SELECT COUNT(1)
            FROM BANK_REQUEST_REPLAY_GUARD
            WHERE BANK_CLIENT_ID = :bank_client_id
              AND REQUEST_ID = :request_id
              AND EXPIRES_AT > SYSDATE
        SQL;

        return (int) $this->conn->fetchOne($sql, [
            'bank_client_id' => $bankClientId,
            'request_id' => $requestId,
        ]) > 0;
    }

    public function insert(int $bankClientId, string $requestId, int $windowSeconds = 900): void
    {
        $sql = <<<SQL
            INSERT INTO BANK_REQUEST_REPLAY_GUARD (
                BANK_CLIENT_ID,
                REQUEST_ID,
                CREATED_AT,
                EXPIRES_AT
            ) VALUES (
                :bank_client_id,
                :request_id,
                SYSDATE,
                SYSDATE + (:window_seconds / 86400)
            )
        SQL;

        $this->conn->executeStatement($sql, [
            'bank_client_id' => $bankClientId,
            'request_id' => $requestId,
            'window_seconds' => $windowSeconds,
        ]);
    }
}