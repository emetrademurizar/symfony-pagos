<?php

namespace App\Utils;
use Doctrine\DBAL\Connection;

class Validator
{
    public function __construct(
        private readonly Connection $conn
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
        $sql = "
            SELECT CODIGO, USUARIO, PASSWORD, CAJA, ESTATUS
            FROM usuarios_ws_bancos
            WHERE UPPER(USUARIO) = UPPER(:usuario)
        ";

        $stmt = $this->conn->prepare($sql);
        $result = $stmt->executeQuery([
            'usuario' => trim($usuario),
        ]);

        $row = $result->fetchAssociative();

        if (!$row) {
            return false;
        }

        if (($row['ESTATUS'] ?? '') !== 'A') {
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
        ];
    }
}