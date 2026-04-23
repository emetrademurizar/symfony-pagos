<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class BankAccessTokenRepository
{
    public function __construct(
        private readonly Connection $conn
    ) {
    }

    public function insert(array $data): void
    {
        $sql = <<<SQL
            INSERT INTO BANK_ACCESS_TOKENS (
                BANK_CLIENT_ID,
                TOKEN_HASH,
                TOKEN_PREFIX,
                ISSUED_AT,
                EXPIRES_AT,
                STATUS,
                CREATED_AT
            ) VALUES (
                :bank_client_id,
                :token_hash,
                :token_prefix,
                SYSDATE,
                TO_DATE(:expires_at, 'YYYY-MM-DD HH24:MI:SS'),
                :status,
                SYSDATE
            )
        SQL;

        $this->conn->executeStatement($sql, [
            'bank_client_id' => $data['bank_client_id'],
            'token_hash' => $data['token_hash'],
            'token_prefix' => $data['token_prefix'],
            'expires_at' => $data['expires_at'],
            'status' => $data['status'],
        ]);
    }

    public function revokeActiveTokensByBankClientId(int $bankClientId): void
    {
        $sql = <<<SQL
            UPDATE BANK_ACCESS_TOKENS
            SET STATUS = 'REVOKED',
                REVOKED_AT = SYSDATE
            WHERE BANK_CLIENT_ID = :bank_client_id
              AND STATUS = 'ACTIVE'
              AND EXPIRES_AT > SYSDATE
        SQL;

        $this->conn->executeStatement($sql, [
            'bank_client_id' => $bankClientId,
        ]);
    }
}