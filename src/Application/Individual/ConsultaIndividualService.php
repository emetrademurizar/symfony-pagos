<?php

namespace App\Application\Individual;
use Doctrine\DBAL\Connection;

class ConsultaIndividualService
{
    public function __construct(
        private readonly Connection $conn,
    ){}

    public function execute(
        string $tipoPlaca,
        string $placa,
        string $usuario,
        string $pass
    ): array {
        // 1) Validar usuario
        if ($usuario !== 'demo' || $pass !== 'demo123') {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO O PASSWORD INVALIDO',
                ],
            ];
        }

        // 2) Validar placa
        if ($tipoPlaca === '' || $placa === '') {
            return [
                'error' => [
                    'cod' => '002',
                    'mensaje' => 'PLACA NO VALIDA',
                ],
            ];
        }

        $tipoPlaca = strtoupper($tipoPlaca);
        $placa = strtoupper($placa);

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
            )
            SELECT
                rt.CIUDAD,
                coalesce(r.agente_emitida, cp.agente) as agente,
                CASE coalesce(cp.estatus, r.status)
                    WHEN 'C' THEN 'COLOCADO'
                    WHEN 'T' THEN 'ASIGNADO LIBERO'
                    WHEN 'P' THEN 'PAGADO'
                    WHEN 'L' THEN 'LIBERADO'
                    WHEN 'N' THEN 'NO PAGADO'
                END AS estado,
                rt.SERIE,
                rt.REMISION,
                rt.TIPO_PLACA,
                rt.PLACA,
                rt.VALOR,
                TO_CHAR(rt.FECHA, 'DD/MM/YYYY') AS fecha,
                rt.TOTAL,
                rt.SALDO,
                CASE rt.serie WHEN 'X'
                    THEN REPLACE(coalesce(rt.LUGAR,coalesce(cp.lugar,c.descripcion)), '_', ' ')
                WHEN 'W'
                    THEN REPLACE(coalesce(rt.LUGAR,ins.lugar), '_', ' ')
                ELSE
                    REPLACE(coalesce(rt.LUGAR,r.lugar), '_', ' ')||'\nArticulos: '||
                    LISTAGG(TO_CHAR(ARTICULO) || ' - ' || TRIM(NUMERAL), ', ')
                        WITHIN GROUP (ORDER BY ARTICULO)
                END AS lugar,
                rt.NOMBRE,
                rt.FECHA_NOTIFICADA,
                LISTAGG(TO_CHAR(ARTICULO) || ' - ' || TRIM(NUMERAL), ', ')
                    WITHIN GROUP (ORDER BY ARTICULO) as ARTICULOS
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
            GROUP BY
                rt.tipo_placa, rt.placa, rt.ciudad, rt.serie, rt.remision,
                rt.valor, rt.fecha, rt.total, rt.saldo, rt.lugar, rt.nombre,
                rt.fecha_notificada, c.descripcion, cp.lugar, ins.lugar, r.lugar,
                r.agente_emitida, cp.agente, cp.estatus, r.status
            ORDER BY rt.fecha_notificada DESC NULLS LAST, rt.fecha DESC 
            SQL;

        try {
            $rows = $this->conn->fetchAllAssociative($sql, [
                'tipo'  => $tipoPlaca,
                'placa' => $placa,
            ]);
        } catch (\Throwable $e){
            $params = $this->conn->getParams();
            unset($params['password']);

            return [
                'error' => [
                    'cod' => '999',
                    'mensaje' => 'ERROR CONEXION BD: ' . $e->getMessage(),
                ],
            ];
        }

        if (!$rows) {
            return [
                'error' => [
                    'cod' => '003',
                    'mensaje' => 'SIN REMISIONES PENDIENTES DE PAGO',
                ],
            ];
        }

        // 4) Simulación de datos
        return [
            'remisiones' => [
                [
                    'serie' => 'T',
                    'numero' => '2142',
                    'nombre' => 'SIN DATOS DEL CONDUCTOR',
                    'fecha' => '2021-01-19',
                    'total' => $total,
                ],
            ],
        ];
    }
}
