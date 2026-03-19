<?php

namespace App\Application\Individual;

use Doctrine\DBAL\Connection;
use App\Utils\Validator;
use App\Utils\Bitacora;

class TotalConsultaService
{
    private const TIPO_OPERACION_BITACORA = '4';

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

    public function execute(
        string $tipoPlaca,
        string $placa,
        string $usuario,
        string $clave,
        string $ip = ''
    ): array {
        $tipoPlaca = strtoupper(trim($tipoPlaca));
        $placa = strtoupper(trim($placa));

        $userData = $this->validator->validUser($usuario, $clave);

        if (!$userData) {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO Y/O CONTRASEÑA NO VALIDOS',
                ],
            ];
        }


        if ($tipoPlaca === '' || !$this->validator->validPlaca($placa)) {
            $this->bitacora->bitacora(
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
                codRespuesta: '002',
                tipoPlaca: $tipoPlaca,
                placa: $placa
            );
            $this->commitBitacora();
            return [
                'error' => [
                    'cod' => '002',
                    'mensaje' => 'PLACA NO VALIDA',
                ],
            ];
        }

        $sql = <<<'SQL'
            WITH placas_filtradas AS (
                SELECT
                    CASE
                        WHEN tipo_placa = :tipo AND placa_actual = :placa
                            THEN tipo_placa_anterior || placa_anterior
                        WHEN tipo_placa_anterior = :tipo AND placa_anterior = :placa
                            THEN tipo_placa || placa_actual
                    END AS placa_completa
                FROM tb_empalme_placas

                UNION

                SELECT :tipo || :placa FROM dual
            ),
            remisiones_filtradas AS (
                SELECT *
                FROM tb_remisiones_temp
                WHERE (tipo_placa || placa) IN (SELECT placa_completa FROM placas_filtradas)
                AND saldo > 0
            ),
            consulta AS (
                SELECT
                    TO_CHAR(rt.FECHA, 'YYYY-MM-DD') AS fecha_iso,
                    rt.TOTAL AS total
                FROM remisiones_filtradas rt
                LEFT JOIN emt_detalles_infraccion det
                    ON rt.remision = det.remision AND rt.serie = det.serie
                LEFT JOIN emt_reglamento_de_transito reg
                    ON det.regla = reg.regla
                LEFT JOIN emt_remisiones r
                    ON r.serie = rt.serie AND r.REMISION = rt.remision
                LEFT JOIN tb_cepos_pmt cp
                    ON rt.serie = 'X' AND cp.cepo = rt.remision
                LEFT JOIN emt_cruceros c
                    ON cp.crucero = c.crucero
                LEFT JOIN emt_vehiculos v
                    ON rt.tipo_placa = v.vehi_tipo_placa AND rt.placa = v.placa
                LEFT JOIN tb_remisiones_instituciones ins
                    ON rt.serie = 'W' AND ins.remision = rt.remision AND v.vehiculo = ins.vehiculo
                GROUP BY rt.fecha, rt.total, rt.fecha_notificada
            )
            SELECT
                MAX(fecha_iso) AS fecha,
                NVL(SUM(total), 0) AS total
            FROM consulta
            SQL;

        try {
            $row = $this->conn->fetchAssociative($sql, [
                'tipo'  => $tipoPlaca,
                'placa' => $placa,
            ]);
        } catch (\Throwable $e) {
            $this->bitacora->bitacora(
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
                codRespuesta: '999',
                tipoPlaca: $tipoPlaca,
                placa: $placa
            );
            $this->commitBitacora();
            return [
                'error' => [
                    'cod' => '999',
                    'mensaje' => 'ERROR CONEXION BD: ' . $e->getMessage(),
                ],
            ];
        }

        if (!$row || ($row['FECHA'] ?? $row['fecha'] ?? null) === null) {
            $this->bitacora->bitacora(
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
                codRespuesta: '003',
                tipoPlaca: $tipoPlaca,
                placa: $placa
            );
            $this->commitBitacora();
            return [
                'error' => [
                    'cod' => '003',
                    'mensaje' => 'SIN REMISIONES PENDIENTES DE PAGO',
                ],
            ];
        }

        $fecha = (string)($row['FECHA'] ?? $row['fecha'] ?? '');
        $total = (float)($row['TOTAL'] ?? $row['total'] ?? 0);

        $this->bitacora->bitacora(
            ip: $ip,
            usuario: $usuario,
            serie: '',
            remision: '',
            referencia: '',
            autorizacion: '',
            operacion: self::TIPO_OPERACION_BITACORA,
            totalOperacion: $total,
            totalPago: $total,
            estatus: 'EXITOSO',
            codRespuesta: '000',
            tipoPlaca: $tipoPlaca,
            placa: $placa
        );
        $this->commitBitacora();
        return [
            'total' => [
                'fecha' => $fecha,
                'total' => $total,
            ],
        ];
    }
}