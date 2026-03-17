<?php

namespace App\Application\Individual;

use Doctrine\DBAL\Connection;
use App\Utils\Validator;
use App\Utils\Bitacora;

class ReversionIndividualService
{
    // Ajustá este valor si reversión usa otro código en bitácora
    private const TIPO_OPERACION_BITACORA = '3';
    private const TIPO_OPERACION_PAQUETE  = 'N';

    public function __construct(
        private readonly Connection $conn,
        private readonly Validator $validator,
        private readonly Bitacora $bitacora
    ) {}

    private function commitBitacora(): void
    {
        $oci = $this->conn->getNativeConnection();

        if (!oci_commit($oci)) {
            $e = oci_error($oci);
            throw new \RuntimeException($e['message'] ?? 'ERROR AL CONFIRMAR BITACORA');
        }
    }

    /**
     * @param array<int, array{
     *   serie?: string,
     *   remision?: string,
     *   total?: float|int|string,
     *   no_referencia?: int|string,
     *   no_autorizacion?: int|string
     * }> $remisiones
     */
    public function execute(
        array $remisiones,
        string $documento,
        string $usuario,
        string $pass,
        string $ip = ''
    ): array {
        $documento = trim($documento);

        $userData = $this->validator->validUser($usuario, $pass);

        if (!$userData) {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO O PASSWORD INVALIDO',
                ],
            ];
        }

        $codigoUsuario = $userData['codigo'];

        if (count($remisiones) === 0) {
            $this->bitacora->bitacora(
                codigo: $codigoUsuario,
                ip: $ip,
                usuario: $usuario,
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
                placa: ''
            );
            $this->commitBitacora();

            return [
                'reversion' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

        $r = $remisiones[0];

        $serie          = strtoupper(trim((string)($r['serie'] ?? '')));
        $remision       = trim((string)($r['remision'] ?? ''));
        $total          = (float)($r['total'] ?? 0);
        $noReferencia   = trim((string)($r['no_referencia'] ?? ''));
        $noAutorizacion = trim((string)($r['no_autorizacion'] ?? ''));

        $doc = trim($serie . '-' . $remision, '-');

        if ($documento === '' || !is_numeric($documento)) {
            $this->bitacora->bitacora(
                codigo: $codigoUsuario,
                ip: $ip,
                usuario: $usuario,
                serie: $serie,
                remision: $remision,
                referencia: $noReferencia,
                autorizacion: $noAutorizacion,
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: $total,
                totalPago: $total,
                estatus: 'ERROR',
                codRespuesta: '004',
                tipoPlaca: '',
                placa: ''
            );
            $this->commitBitacora();

            return [
                'reversion' => [
                    'doc' => $doc,
                    'cod' => '004',
                    'mensaje' => 'DOCUMENTO NO VALIDO',
                ],
            ];
        }

        $oci = $this->conn->getNativeConnection();

        try {
            $sql = "
                BEGIN
                    sp_reversar_documento(
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
            oci_bind_by_name($stmt, ':p_usuario_graba', $usuario);

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
                codigo: $codigoUsuario,
                ip: $ip,
                usuario: $usuario,
                serie: $serie,
                remision: $remision,
                referencia: $noReferencia,
                autorizacion: $noAutorizacion,
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: $total,
                totalPago: $total,
                estatus: 'EXITOSO',
                codRespuesta: '000',
                tipoPlaca: '',
                placa: ''
            );
            $this->commitBitacora();

            return [
                'reversion' => [
                    'doc' => $doc,
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
                codigo: $codigoUsuario,
                ip: $ip,
                usuario: $usuario,
                serie: $serie,
                remision: $remision,
                referencia: $noReferencia,
                autorizacion: $noAutorizacion,
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: $total,
                totalPago: $total,
                estatus: 'ERROR',
                codRespuesta: '999',
                tipoPlaca: '',
                placa: ''
            );
            $this->commitBitacora();

            return [
                'error' => [
                    'cod' => '999',
                    'mensaje' => 'ERROR CONEXION BD: ' . $e->getMessage(),
                ],
            ];
        }
    }
}