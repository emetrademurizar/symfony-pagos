<?php

namespace App\Application\Security;

use Doctrine\DBAL\Connection;

final class BankRequestLoggerService
{
    public function __construct(
        private readonly Connection $conn
    ) {}

    public function logRequest(
        int $bankClientId,
        string $requestId,
        ?string $operation,
        string $requestTimestamp,
        string $clientIp,
        string $payload,
        string $authResult = 'OK',
        string $replayResult = 'OK',
        string $rateLimitResult = 'OK',
        ?string $comments = null
    ): int {
        $payloadHash = hash('sha256', $payload);

        $sql = "
            INSERT INTO BANK_REQUEST_LOG (
                BANK_CLIENT_ID,
                REQUEST_ID,
                OPERATION,
                REQUEST_TIMESTAMP,
                RECEIVED_AT,
                CLIENT_IP,
                PAYLOAD_HASH,
                AUTH_RESULT,
                REPLAY_RESULT,
                RATE_LIMIT_RESULT,
                COMMENTS
            ) VALUES (
                :bank_client_id,
                :request_id,
                :operation,
                :request_timestamp,
                SYSDATE,
                :client_ip,
                :payload_hash,
                :auth_result,
                :replay_result,
                :rate_limit_result,
                :comments
            )
        ";

        $this->conn->executeStatement($sql, [
            'bank_client_id' => $bankClientId,
            'request_id' => $requestId,
            'operation' => $operation,
            'request_timestamp' => $requestTimestamp,
            'client_ip' => $clientIp,
            'payload_hash' => $payloadHash,
            'auth_result' => $authResult,
            'replay_result' => $replayResult,
            'rate_limit_result' => $rateLimitResult,
            'comments' => $comments,
        ]);

        return (int) $this->conn->fetchOne("
            SELECT MAX(ID)
            FROM BANK_REQUEST_LOG
            WHERE BANK_CLIENT_ID = :bank_client_id
              AND REQUEST_ID = :request_id
        ", [
            'bank_client_id' => $bankClientId,
            'request_id' => $requestId,
        ]);
    }

    public function closeRequest(
        int $logId,
        int $httpStatus,
        ?string $functionalCode,
        string $responseBody
    ): void {
        $responseHash = hash('sha256', $responseBody);

        $sql = "
            UPDATE BANK_REQUEST_LOG
            SET HTTP_STATUS = :http_status,
                FUNCTIONAL_CODE = :functional_code,
                RESPONSE_HASH = :response_hash
            WHERE ID = :id
        ";

        $this->conn->executeStatement($sql, [
            'http_status' => $httpStatus,
            'functional_code' => $functionalCode,
            'response_hash' => $responseHash,
            'id' => $logId,
        ]);
    }

    public function logRejectedRequest(
        ?int $bankClientId,
        ?string $requestId,
        ?string $operation,
        ?string $requestTimestamp,
        string $clientIp,
        string $payload,
        int $httpStatus,
        string $authResult,
        ?string $replayResult = null,
        ?string $rateLimitResult = null,
        ?string $comments = null
    ): void {
        $payloadHash = hash('sha256', $payload);

        $sql = "
            INSERT INTO BANK_REQUEST_LOG (
                BANK_CLIENT_ID,
                REQUEST_ID,
                OPERATION,
                REQUEST_TIMESTAMP,
                RECEIVED_AT,
                HTTP_STATUS,
                FUNCTIONAL_CODE,
                PAYLOAD_HASH,
                RESPONSE_HASH,
                CLIENT_IP,
                AUTH_RESULT,
                REPLAY_RESULT,
                RATE_LIMIT_RESULT,
                COMMENTS
            ) VALUES (
                :bank_client_id,
                :request_id,
                :operation,
                :request_timestamp,
                SYSDATE,
                :http_status,
                NULL,
                :payload_hash,
                NULL,
                :client_ip,
                :auth_result,
                :replay_result,
                :rate_limit_result,
                :comments
            )
        ";

        $this->conn->executeStatement($sql, [
            'bank_client_id' => $bankClientId,
            'request_id' => $requestId,
            'operation' => $operation,
            'request_timestamp' => $requestTimestamp,
            'http_status' => $httpStatus,
            'payload_hash' => $payloadHash,
            'client_ip' => $clientIp,
            'auth_result' => $authResult,
            'replay_result' => $replayResult,
            'rate_limit_result' => $rateLimitResult,
            'comments' => $comments,
        ]);
    }
}