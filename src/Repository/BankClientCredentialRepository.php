<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class BankClientCredentialRepository
{
    public function __construct(
        private readonly Connection $conn
    ) {
    }

    public function countByBankClientId(int $bankClientId): int
    {
        $sql = <<<SQL
            SELECT COUNT(1)
            FROM BANK_CLIENT_CREDENTIALS
            WHERE BANK_CLIENT_ID = :bank_client_id
        SQL;

        return (int) $this->conn->fetchOne($sql, [
            'bank_client_id' => $bankClientId,
        ]);
    }

    public function existsClientId(string $clientId): bool
    {
        $sql = <<<SQL
            SELECT COUNT(1)
            FROM BANK_CLIENT_CREDENTIALS
            WHERE CLIENT_ID = :client_id
        SQL;

        return (int) $this->conn->fetchOne($sql, [
            'client_id' => $clientId,
        ]) > 0;
    }

    public function insert(array $data): void
    {
        $sql = <<<SQL
            INSERT INTO BANK_CLIENT_CREDENTIALS (
                BANK_CLIENT_ID,
                CLIENT_ID,
                CLIENT_SECRET_HASH,
                LABEL,
                STATUS,
                EXPIRES_AT,
                CREATED_AT
            ) VALUES (
                :bank_client_id,
                :client_id,
                :client_secret_hash,
                :label,
                :status,
                TO_DATE(:expires_at, 'YYYY-MM-DD HH24:MI:SS'),
                SYSDATE
            )
        SQL;

        $this->conn->executeStatement($sql, [
            'bank_client_id' => $data['bank_client_id'],
            'client_id' => $data['client_id'],
            'client_secret_hash' => $data['client_secret_hash'],
            'label' => $data['label'],
            'status' => $data['status'],
            'expires_at' => $data['expires_at'],
        ]);
    }

    public function findActiveByClientId(string $clientId): ?array
    {
        $sql = <<<SQL
            SELECT
                ID,
                BANK_CLIENT_ID,
                CLIENT_ID,
                CLIENT_SECRET_HASH,
                LABEL,
                STATUS,
                EXPIRES_AT,
                LAST_USED_AT,
                CREATED_AT
            FROM BANK_CLIENT_CREDENTIALS
            WHERE CLIENT_ID = :client_id
              AND STATUS = 'ACTIVE'
        SQL;

        $row = $this->conn->fetchAssociative($sql, [
            'client_id' => $clientId,
        ]);

        return $row ?: null;
    }

    public function markLastUsed(int $credentialId): void
    {
        $sql = <<<SQL
            UPDATE BANK_CLIENT_CREDENTIALS
            SET LAST_USED_AT = SYSDATE
            WHERE ID = :id
        SQL;

        $this->conn->executeStatement($sql, [
            'id' => $credentialId,
        ]);
    }
}