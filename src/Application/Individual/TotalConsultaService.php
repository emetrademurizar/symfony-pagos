<?php

namespace App\Application\Individual;
use Doctrine\DBAL\Connection;
use App\Utils\Validator;

class TotalConsultaService
{
    public function __construct(
        private readonly Connection $conn,
        private readonly Validator $Validator
    ){}

    /**
     * @param string $tipoPlaca
     * @param string $placa
     * @param string $usuario
     * @param string $clave
     */
    public function execute(string $tipoPlaca, string $placa, string $usuario, string $clave): array
    {
        // Lógica de validación de usuario (dummy, después conectas lógica real)
        if ($usuario !== 'demo' || $clave !== 'demo123') {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO Y/O CONTRASEÑA NO VALIDOS',
                ],
            ];
        }

        // 2) Validación de placa
        $tipoPlaca = strtoupper(trim($tipoPlaca));
        $placa = strtoupper(trim($placa));

        if ($tipoPlaca === '' || !$this->Validator->validPlaca($placa)) {
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
        
        try{
            $row = $this->conn->fetchAssociative($sql, [
                'tipo'  => $tipoPlaca,
                'placa' => $placa,
            ]);
        } catch (\Exception $e) {
            return [
                'error' => [
                    'cod' => '999',
                    // 'mensaje' => 'ERROR INTERNO DEL SERVIDOR',
                    'mensaje' => 'ERROR CONEXION BD: ' . $e->getMessage(),
                ],
            ];
        }

        if (!$row || ($row['FECHA'] ?? $row['fecha'] ?? null) === null) {
            return [
                'error' => [
                    'cod' => '003',
                    'mensaje' => 'SIN REMISIONES PENDIENTES DE PAGO',
                ],
            ];
        }

        $fecha = $row['FECHA'] ?? $row['fecha'] ?? '';
        $total = $row['TOTAL'] ?? $row['total'] ?? 0;

        // Si no se encuentra un total, por ejemplo si no se encuentra la placa
        return [
            'total' => [
                'fecha' => $fecha,
                'total' => $total,
            ],
        ];
    }
}