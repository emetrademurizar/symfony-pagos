<?php

namespace App\Application\Individual;

use Doctrine\DBAL\Connection;
use App\Utils\Validator;
use App\Utils\Bitacora;
use Psr\Log\LoggerInterface;

class ReversionIndividualService
{
    private const TIPO_OPERACION_BITACORA = '3';
    private const TIPO_OPERACION_PAQUETE  = 'W';

    public function __construct(
        private readonly Connection $conn,
        private readonly Validator $validator,
        private readonly Bitacora $bitacora,
        private readonly LoggerInterface $logger,
    ) {}

    private function commitBitacora(): void
    {
        $oci = $this->conn->getNativeConnection();

        if (!oci_commit($oci)) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL CONFIRMAR BITACORA');
        }
    }

    public function execute(
        string $documento,
        string $usuario,
        // string $pass,
        string $message,
        string $ip = ''
    ): array {
        $documento = trim($documento);

        // $userData = $this->validator->validUser($usuario, $pass);
        $userData = $this->validator->getInfoUser($usuario);


        $usuario_graba = $userData['caja'] ?? '';
        $this->logger->info('Solicitud usuario recibida', [
            'user'    => $usuario,
            'data'    => $userData,
        ]);

        $nombreBanco = $userData['bank_name'] . ' ' . $userData['environment'];

        if ($documento === '' || !ctype_digit($documento)) {
            $this->bitacora->bitacora(
                ip: $ip,
                usuario: $usuario,
                nombreBanco: $nombreBanco,                
                serie: '',
                remision: '',
                referencia: '',
                autorizacion: '',
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: 0,
                totalPago: 0,
                estatus: 'ERROR',
                codRespuesta: '004',
                tipoPlaca: '',
                placa: '',
                comentarios: 'DOCUMENTO NO VALIDO',
                doc: $documento
            );
            $this->commitBitacora();

            return [
                'reversion' => [
                    'doc' => $documento,
                    'cod' => '004',
                    'mensaje' => 'DOCUMENTO NO VALIDO',
                ],
            ];
        }

        try {
            $pago = $this->bitacora->obtenerPagoPorDocumento($documento);
        } catch (\Throwable $e) {
            $this->logger->info('Error en busqueda de pago para reversa', $pago);
            return [
                'error' => [
                    'cod' => '999',
                    'mensaje' => 'ERROR CONEXION BD:' . $e->getMessage(),
                ],
            ];
        }
        $this->logger->info('Resultado de busqueda de pago', $pago);

        if ($pago === false) {
            $this->bitacora->bitacora(
                ip: $ip,
                usuario: $usuario,
                nombreBanco: $nombreBanco,  
                serie: '',
                remision: '',
                referencia: '',
                autorizacion: '',
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: 0,
                totalPago: 0,
                estatus: 'ERROR',
                codRespuesta: '004',
                tipoPlaca: '',
                placa: '',
                doc: $documento
            );
            $this->commitBitacora();

            return [
                'reversion' => [
                    'doc' => $documento,
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

        $total          = (float)($pago['total'] ?? 0);
        $noReferencia   = trim((string)($pago['no_referencia'] ?? ''));
        $noAutorizacion = trim((string)($pago['no_autorizacion'] ?? ''));
        $tipoPlaca      = strtoupper(trim((string)($pago['tipo_placa'] ?? '')));
        $placa          = strtoupper(trim((string)($pago['placa'] ?? '')));
        $fechaPagoRaw   = $pago['fecha'] ?? null;

        if ($fechaPagoRaw instanceof \DateTimeInterface) {
            $fechaPago = $fechaPagoRaw->format('Y-m-d');
        } else {
            $timestamp = strtotime((string)$fechaPagoRaw);
            $fechaPago = $timestamp ? date('Y-m-d', $timestamp) : '';
        }

        $hoy = date('Y-m-d');

        if ($fechaPago === '' || $fechaPago !== $hoy) {
            $this->bitacora->bitacora(
                ip: $ip,
                usuario: $usuario,
                nombreBanco: $nombreBanco,  
                serie: '',
                remision: '',
                referencia: $noReferencia,
                autorizacion: $noAutorizacion,
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: $total,
                totalPago: $total,
                estatus: 'ERROR',
                codRespuesta: '002',
                tipoPlaca: $tipoPlaca,
                placa: $placa,
                comentarios: 'SE HA EXCEDIDO LA FECHA',
                doc: $documento
            );
            $this->commitBitacora();

            return [
                'reversion' => [
                    'doc' => $documento,
                    'cod' => '002',
                    'mensaje' => 'SE HA EXCEDIDO LA FECHA',
                ],
            ];
        }

        $oci = $this->conn->getNativeConnection();

        try {
            $sql = "
                BEGIN
                    admemetra.pkg_pago_servicios.sp_reversar_documento(
                        p_numero_recibo => :p_numero_recibo,
                        p_respuesta     => :p_respuesta,
                        p_tipo_opera    => :p_tipo_opera,
                        p_usuario_graba => :p_usuario_graba
                    );
                END;
            ";

            $stmt = oci_parse($oci, $sql);

            if (!$stmt) {
                $e = oci_error($oci);
                throw new \RuntimeException($e['message'] ?? 'ERROR AL PREPARAR REVERSA');
            }

            $respuesta = '';
            $numeroRecibo = (int)$documento;
            $tipoOperacion = self::TIPO_OPERACION_PAQUETE;

            oci_bind_by_name($stmt, ':p_numero_recibo', $numeroRecibo);
            oci_bind_by_name($stmt, ':p_respuesta', $respuesta, 4000);
            oci_bind_by_name($stmt, ':p_tipo_opera', $tipoOperacion);
            oci_bind_by_name($stmt, ':p_usuario_graba', $usuario_graba);

            $ok = oci_execute($stmt, OCI_NO_AUTO_COMMIT);

            if (!$ok) {
                $e = oci_error($stmt);
                throw new \RuntimeException($e['message'] ?? 'ERROR AL EJECUTAR REVERSA');
            }

            if (!oci_commit($oci)) {
                $e = oci_error($oci);
                throw new \RuntimeException($e['message'] ?? 'ERROR AL CONFIRMAR REVERSA');
            }

            oci_free_statement($stmt);

            $this->bitacora->bitacora(
                ip: $ip,
                usuario: $usuario,
                nombreBanco: $nombreBanco,  
                serie: '',
                remision: '',
                referencia: $noReferencia,
                autorizacion: $noAutorizacion,
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: $total,
                totalPago: $total,
                estatus: 'EXITOSO',
                codRespuesta: '000',
                tipoPlaca: $tipoPlaca,
                placa: $placa,
                comentarios: $message,
                doc: $documento
            );
            $this->commitBitacora();

            return [
                'reversion' => [
                    'doc' => $documento,
                    'cod' => '000',
                    'mensaje' => trim($respuesta),
                ],
            ];
        } catch (\Throwable $e) {
            if (isset($stmt)) {
                @oci_free_statement($stmt);
            }

            @oci_rollback($oci);

            $this->bitacora->bitacora(
                ip: $ip,
                usuario: $usuario,
                nombreBanco: $nombreBanco,  
                serie: $serie,
                remision: $remision,
                referencia: $noReferencia,
                autorizacion: $noAutorizacion,
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: $total,
                totalPago: $total,
                estatus: 'ERROR',
                codRespuesta: '999',
                tipoPlaca: $tipoPlaca,
                placa: $placa,
                doc: $documento
            );
            $this->commitBitacora();

            return [
                'error' => [
                    'cod' => '999',
                    'mensaje' => 'ERROR CONEXION BD: 1' . $e->getMessage(),
                ],
            ];
        }
    }
}