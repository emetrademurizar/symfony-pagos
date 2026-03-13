<?php

namespace App\Utils;

use Doctrine\DBAL\Connection;

class Bitacora
{
    public function __construct(
        private readonly Connection $conn,
    ) {}

    public function bitacora(
        string $codigo,
        string $ip,
        string $usuario,
        ?string $serie,
        ?string $remision,
        ?string $referencia,
        ?string $autorizacion,
        string $operacion,
        float $totalOperacion,
        float $totalPago,
        string $estatus,
        string $codRespuesta,
        ?string $tipoPlaca,
        ?string $placa
    ): bool {
        $serie = !empty($serie) ? $serie : '';
        $remision = !empty($remision) ? $remision : '';
        $autorizacion = !empty($autorizacion) ? $autorizacion : '';
        $referencia = !empty($referencia) ? $referencia : '';
        $tipoPlaca = !empty($tipoPlaca) ? $tipoPlaca : '';
        $placa = !empty($placa) ? $placa : '';

        $transaccion = $this->nextBitacora();
        $oci = $this->conn->getNativeConnection();

        $sql = <<<'SQL'
            INSERT INTO HISTORIAL_BANCOS(
                TRANSACCION,
                CODIGO,
                IP,
                FECHA,
                USUARIO,
                SERIE,
                REMISION,
                NO_REFERENCIA,
                NO_AUTORIZA,
                TIPO_OPERACION,
                TOTAL_OPERACION,
                TOTAL_PAGAR_OPERACION,
                ESTATUS,
                CODIGO_RESPUESTA,
                TIPO_PLACA,
                PLACA
            ) VALUES(
                :TRANSACCION,
                :CODIGO,
                :IP,
                SYSDATE,
                :USUARIO,
                :SERIE,
                :REMISION,
                :NO_REFERENCIA,
                :NO_AUTORIZACION,
                :TIPO_OPERACION,
                :TOTAL_OPERACION,
                :TOTAL_PAGAR_OPERACION,
                :ESTATUS,
                :COD_RESPUESTA,
                :T_PLACA,
                :PLACA
            )
        SQL;

        $stm = oci_parse($oci, $sql);

        if ($stm === false) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'NO SE PUDO PREPARAR INSERT DE BITACORA');
        }

        oci_bind_by_name($stm, ':TRANSACCION', $transaccion);
        oci_bind_by_name($stm, ':CODIGO', $codigo);
        oci_bind_by_name($stm, ':IP', $ip);
        oci_bind_by_name($stm, ':USUARIO', $usuario);
        oci_bind_by_name($stm, ':SERIE', $serie);
        oci_bind_by_name($stm, ':REMISION', $remision);
        oci_bind_by_name($stm, ':NO_REFERENCIA', $referencia);
        oci_bind_by_name($stm, ':NO_AUTORIZACION', $autorizacion);
        oci_bind_by_name($stm, ':TIPO_OPERACION', $operacion);
        oci_bind_by_name($stm, ':TOTAL_OPERACION', $totalOperacion);
        oci_bind_by_name($stm, ':TOTAL_PAGAR_OPERACION', $totalPago);
        oci_bind_by_name($stm, ':ESTATUS', $estatus);
        oci_bind_by_name($stm, ':COD_RESPUESTA', $codRespuesta);
        oci_bind_by_name($stm, ':T_PLACA', $tipoPlaca);
        oci_bind_by_name($stm, ':PLACA', $placa);

        $ok = oci_execute($stm, OCI_NO_AUTO_COMMIT);

        if ($ok === false) {
            $e = oci_error($stm) ?: oci_error($oci);
            oci_free_statement($stm);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL INSERTAR BITACORA');
        }

        oci_free_statement($stm);

        return true;
    }

    public function nextBitacora(): int
    {
        $oci = $this->conn->getNativeConnection();

        $sql = "SELECT NVL(MAX(TRANSACCION), 0) + 1 AS SIGUIENTE FROM HISTORIAL_BANCOS";
        $stm = oci_parse($oci, $sql);

        if ($stm === false) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'NO SE PUDO PREPARAR NEXT BITACORA');
        }

        $ok = oci_execute($stm, OCI_NO_AUTO_COMMIT);

        if ($ok === false) {
            $e = oci_error($stm) ?: oci_error($oci);
            oci_free_statement($stm);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL OBTENER SIGUIENTE BITACORA');
        }

        $row = oci_fetch_assoc($stm);
        oci_free_statement($stm);

        return (int)($row['SIGUIENTE'] ?? 1);
    }

    public function existeTransaccion(string $referencia, string $autorizacion): bool
    {
        $oci = $this->conn->getNativeConnection();

        $sql = <<<'SQL'
            SELECT COUNT(*) AS TOTAL
            FROM HISTORIAL_BANCOS
            WHERE NO_REFERENCIA = :referencia
            AND NO_AUTORIZA = :autorizacion
        SQL;

        $stm = oci_parse($oci, $sql);

        if ($stm === false) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL PREPARAR CONSULTA DE BITACORA');
        }

        oci_bind_by_name($stm, ':referencia', $referencia);
        oci_bind_by_name($stm, ':autorizacion', $autorizacion);

        $ok = oci_execute($stm, OCI_NO_AUTO_COMMIT);

        if ($ok === false) {
            $e = oci_error($stm) ?: oci_error($oci);
            oci_free_statement($stm);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL CONSULTAR BITACORA');
        }

        $row = oci_fetch_assoc($stm);
        oci_free_statement($stm);

        return ((int)($row['TOTAL'] ?? 0)) > 0;
    }
}