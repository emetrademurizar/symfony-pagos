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

    public function findActiveByHash(string $tokenHash): ?array
    {
        $sql = <<<SQL
            SELECT
                bat.ID AS TOKEN_ID,
                bat.BANK_CLIENT_ID,
                bat.TOKEN_PREFIX,
                TO_CHAR(bat.EXPIRES_AT, 'YYYY-MM-DD HH24:MI:SS') AS EXPIRES_AT,
                bc.BANK_CODE,
                bc.BANK_NAME,
                bc.ENVIRONMENT,
                bc.CAJA,
                bc.RATE_LIMIT_PER_MIN
            FROM BANK_ACCESS_TOKENS bat
            INNER JOIN BANK_CLIENTS bc
                ON bc.ID = bat.BANK_CLIENT_ID
            WHERE bat.TOKEN_HASH = :token_hash
            AND bat.STATUS = 'ACTIVE'
            AND bat.REVOKED_AT IS NULL
            AND bc.STATUS = 'A'
        SQL;

        $row = $this->conn->fetchAssociative($sql, [
            'token_hash' => $tokenHash,
        ]);

        return $row ?: null;
    }
}