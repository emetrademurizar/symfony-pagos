<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class BankClientRepository
{
    public function __construct(
        private readonly Connection $conn
    ) {
    }

    public function findActiveByBankAndEnvironment(string $bankCode, string $environment): ?array
    {
        $sql = <<<SQL
            SELECT
                ID,
                BANK_CODE,
                BANK_NAME,
                ENVIRONMENT,
                STATUS
            FROM BANK_CLIENTS
            WHERE UPPER(BANK_CODE) = UPPER(:bank_code)
              AND UPPER(ENVIRONMENT) = UPPER(:environment)
              AND STATUS = 'A'
        SQL;

        $row = $this->conn->fetchAssociative($sql, [
            'bank_code' => $bankCode,
            'environment' => $environment,
        ]);

        return $row ?: null;
    }
}