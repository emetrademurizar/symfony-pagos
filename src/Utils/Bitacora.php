<?php

namespace App\Utils;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class Bitacora
{
    public function __construct(
        private readonly Connection $conn,
        private readonly LoggerInterface $logger
    ) {}

    public function bitacora(
        string $ip,
        string $usuario,
        string $nombreBanco,
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
        ?string $placa,
        ?string $comentarios = null,
        ?string $doc = null,
    ): bool {
        $serie = !empty($serie) ? $serie : '';
        $remision = !empty($remision) ? $remision : '';
        $autorizacion = !empty($autorizacion) ? $autorizacion : '';
        $referencia = !empty($referencia) ? $referencia : '';
        $tipoPlaca = !empty($tipoPlaca) ? $tipoPlaca : '';
        $placa = !empty($placa) ? $placa : '';
        $comentarios = !empty($comentarios) ? $comentarios : '';
        $doc = !empty($doc) ? $doc : '';
        $usuario = $usuario;

        $transaccion = $this->nextBitacora();
        $oci = $this->conn->getNativeConnection();

        $sql = <<<'SQL'
            INSERT INTO HISTORIAL_BANCOS(
                TRANSACCION,
                IP,
                FECHA,
                BANK_CLIENT_ID,
                NOMBRE_BANCO,
                SERIE,
                REMISION,
                DOCUMENTO,
                NO_REFERENCIA,
                NO_AUTORIZA,
                TIPO_OPERACION,
                TOTAL_OPERACION,
                TOTAL_PAGAR_OPERACION,
                ESTATUS,
                CODIGO_RESPUESTA,
                TIPO_PLACA,
                PLACA,
                COMENTARIOS
            ) VALUES(
                :TRANSACCION,
                :IP,
                SYSDATE,
                :BANK_CLIENT_ID,
                :NOMBRE_BANCO,
                :SERIE,
                :REMISION,
                :DOCUMENTO,
                :NO_REFERENCIA,
                :NO_AUTORIZACION,
                :TIPO_OPERACION,
                :TOTAL_OPERACION,
                :TOTAL_PAGAR_OPERACION,
                :ESTATUS,
                :COD_RESPUESTA,
                :T_PLACA,
                :PLACA,
                :COMENTARIOS
            )
        SQL;

        $stm = oci_parse($oci, $sql);

        if ($stm === false) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'NO SE PUDO PREPARAR INSERT DE BITACORA');
        }

        oci_bind_by_name($stm, ':TRANSACCION', $transaccion);
        oci_bind_by_name($stm, ':IP', $ip);
        oci_bind_by_name($stm, ':BANK_CLIENT_ID', $usuario);
        oci_bind_by_name($stm, ':NOMBRE_BANCO', $nombreBanco);
        oci_bind_by_name($stm, ':SERIE', $serie);
        oci_bind_by_name($stm, ':REMISION', $remision);
        oci_bind_by_name($stm, ':DOCUMENTO', $doc);
        oci_bind_by_name($stm, ':NO_REFERENCIA', $referencia);
        oci_bind_by_name($stm, ':NO_AUTORIZACION', $autorizacion);
        oci_bind_by_name($stm, ':TIPO_OPERACION', $operacion);
        oci_bind_by_name($stm, ':TOTAL_OPERACION', $totalOperacion);
        oci_bind_by_name($stm, ':TOTAL_PAGAR_OPERACION', $totalPago);
        oci_bind_by_name($stm, ':ESTATUS', $estatus);
        oci_bind_by_name($stm, ':COD_RESPUESTA', $codRespuesta);
        oci_bind_by_name($stm, ':T_PLACA', $tipoPlaca);
        oci_bind_by_name($stm, ':PLACA', $placa);
        oci_bind_by_name($stm, ':COMENTARIOS', $comentarios);

        $this->logger->info('data a guardar en bitacora',[
            "usuario: "=>$usuario
        ]);

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

    public function existeTransaccion(string $serie, string $remision, string $referencia, string $autorizacion): bool
    {
        $oci = $this->conn->getNativeConnection();

        $sql = <<<'SQL'
            SELECT COUNT(*) AS TOTAL
            FROM HISTORIAL_BANCOS
            WHERE SERIE = :serie
            AND REMISION = :remision
            AND NO_REFERENCIA = :referencia
            AND NO_AUTORIZA = :autorizacion
            AND TIPO_OPERACION IN ('2', '5')
            AND (ESTATUS = 'EXITOSO' OR CODIGO_RESPUESTA = '000')
        SQL;

        $stm = oci_parse($oci, $sql);

        if ($stm === false) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL PREPARAR CONSULTA DE BITACORA');
        }

        oci_bind_by_name($stm, ':serie', $serie);
        oci_bind_by_name($stm, ':remision', $remision);
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

    public function obtenerPagoPorDocumento(string $documento): array|false
    {
        $oci = $this->conn->getNativeConnection();

        $sql = <<<'SQL'
            SELECT
                NO_REFERENCIA,
                NO_AUTORIZA,
                FECHA,
                tipo_placa,
                placa,
                total_operacion
            FROM HISTORIAL_BANCOS
            WHERE DOCUMENTO = :documento
            AND TIPO_OPERACION IN ('2', '5')
            AND (ESTATUS = 'EXITOSO' OR CODIGO_RESPUESTA = '000')
            ORDER BY FECHA DESC
        SQL;

        $stm = oci_parse($oci, $sql);

        if ($stm === false) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL PREPARAR CONSULTA DE BITACORA');
        }

        oci_bind_by_name($stm, ':documento', $documento);

        $ok = oci_execute($stm, OCI_NO_AUTO_COMMIT);

        if ($ok === false) {
            $e = oci_error($stm) ?: oci_error($oci);
            oci_free_statement($stm);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL CONSULTAR BITACORA');
        }

        $row = oci_fetch_assoc($stm);
        oci_free_statement($stm);

        if (!$row) {
            return false;
        }

        return [
            'no_referencia' => (string)($row['NO_REFERENCIA'] ?? ''),
            'no_autorizacion' => (string)($row['NO_AUTORIZA'] ?? ''),
            'fecha' => $row['FECHA'] ?? null,
            'tipo_placa' => $row['TIPO_PLACA'] ?? null,
            'placa' => $row['PLACA'] ?? null,
            'total' => $row['TOTAL_OPERACION'] ?? null
        ];
    }

    public function obtenerPlacaPorRemision(string $serie, string $remision): array|false
    {
        $oci = $this->conn->getNativeConnection();

        $sql = <<<'SQL'
            SELECT
                tipo_placa,
                placa
            FROM HISTORIAL_BANCOS
            WHERE SERIE = :serie AND
                REMISION = :remision
        SQL;

        $stm = oci_parse($oci, $sql);

        if ($stm === false) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL PREPARAR CONSULTA DE BITACORA');
        }

        oci_bind_by_name($stm, ':serie', $serie);
        oci_bind_by_name($stm, ':remision', $remision);

        $ok = oci_execute($stm, OCI_NO_AUTO_COMMIT);

        if ($ok === false) {
            $e = oci_error($stm) ?: oci_error($oci);
            oci_free_statement($stm);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL CONSULTAR PLACA');
        }

        $row = oci_fetch_assoc($stm);
        oci_free_statement($stm);

        if (!$row) {
            return false;
        }

        return [
            'tipo_placa' => $row['TIPO_PLACA'] ?? null,
            'placa' => $row['PLACA'] ?? null
        ];
    }
}