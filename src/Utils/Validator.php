<?php

namespace App\Utils;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class Validator
{
    public function __construct(
        private readonly Connection $conn,
        private readonly LoggerInterface $logger
    ) {}

    public function validPlaca(string $placa): bool
    {
        $placa = strtoupper(trim($placa));
        $placa = str_replace([' ', '-'], '', $placa);

        $placa = str_pad($placa, 6, '0', STR_PAD_LEFT);

        if (strlen($placa) !== 6) {
            return false;
        }

        $literales = substr($placa, -3);
        $numeros   = substr($placa, 0, 3);

        return ctype_alpha($literales) && ctype_digit($numeros);
    }
    
    public function validUser(string $usuario, string $password): array|false
    {
        $usuario = trim($usuario);

        if ($usuario === '') {
            return false;
        }

        try {
            $sql = "
                SELECT
                    uwb.CODIGO,
                    uwb.USUARIO,
                    uwb.PASSWORD,
                    uwb.CAJA,
                    uwb.ESTATUS AS ESTATUS_USUARIO,
                    ub.ID_USUARIO_BANCO,
                    ub.NOMBRE_BANCO,
                    ub.ESTATUS AS ESTATUS_BANCO
                FROM usuario_banco ub
                INNER JOIN usuarios_ws_bancos uwb
                    ON uwb.CODIGO = ub.CODIGO_USUARIO
                WHERE ub.ID_USUARIO_BANCO = :usuario
            ";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->executeQuery([
                'usuario' => $usuario,
            ]);

            $row = $result->fetchAssociative();

            if (!$row) {
                return false;
            }

            if (($row['ESTATUS_USUARIO'] ?? '') !== 'A') {
                return false;
            }

            if (($row['ESTATUS_BANCO'] ?? '') !== 'A') {
                return false;
            }

            $hashGuardado = $row['PASSWORD'] ?? '';

            if ($hashGuardado === '' || !password_verify($password, $hashGuardado)) {
                return false;
            }

            return [
                'codigo' => $row['CODIGO'],
                'usuario' => $row['USUARIO'],
                'caja' => $row['CAJA'],
                'id_usuario_banco' => $row['ID_USUARIO_BANCO'],
                'nombre_banco' => $row['NOMBRE_BANCO'],
            ];
        } catch (\Throwable) {
            return false;
        }
    }

    public function getInfoUser(int|string $bankClientId): array|false
    {
        $bankClientId = trim($bankClientId);

        if ($bankClientId === '' || !ctype_digit($bankClientId)) {
            return false;
        }

        try {
            $sql = "
                SELECT
                    ID,
                    CODIGO_USUARIO_BANCO,
                    BANK_CODE,
                    BANK_NAME,
                    ENVIRONMENT,
                    STATUS,
                    RATE_LIMIT_PER_MIN,
                    CLOCK_SKEW_SECONDS,
                    CAJA
                FROM BANK_CLIENTS
                WHERE ID = :bank_client_id
            ";

            $stmt = $this->conn->prepare($sql);
            $result = $stmt->executeQuery([
                'bank_client_id' => (int) $bankClientId,
            ]);

            $row = $result->fetchAssociative();

            if (!$row) {
                return false;
            }

            if (($row['STATUS'] ?? '') !== 'A') {
                return false;
            }

            return [
                'bank_client_id' => (int) $row['ID'],
                'codigo_usuario_banco' => (int) $row['CODIGO_USUARIO_BANCO'],
                'bank_code' => $row['BANK_CODE'],
                'bank_name' => $row['BANK_NAME'],
                'environment' => $row['ENVIRONMENT'],
                'caja' => $row['CAJA'],
                'rate_limit_per_min' => (int) $row['RATE_LIMIT_PER_MIN'],
                'clock_skew_seconds' => (int) $row['CLOCK_SKEW_SECONDS'],
            ];
        } catch (\Throwable) {
            return false;
        }
    }

}