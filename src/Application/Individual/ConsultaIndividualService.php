<?php

namespace App\Application\Individual;

use Doctrine\DBAL\Connection;
use App\Utils\Validator;
use App\Utils\Bitacora;

class ConsultaIndividualService
{
    private const TIPO_OPERACION_BITACORA = '1';

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
        string $pass,
        string $ip = ''
    ): array {
        $tipoPlaca = strtoupper(trim($tipoPlaca));
        $placa = strtoupper(trim($placa));

        $userData = $this->validator->validUser($usuario, $pass);

        if (!$userData) {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO O PASSWORD INVALIDO',
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

        if (!$rows) {
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

        $remisiones = array_map(static function (array $r): array {
            $serie  = $r['SERIE'] ?? $r['serie'] ?? null;
            $numero = $r['REMISION'] ?? $r['remision'] ?? null;
            $nombre = $r['NOMBRE'] ?? $r['nombre'] ?? null;
            $fecha  = $r['FECHA'] ?? $r['fecha'] ?? null;
            $total  = $r['TOTAL'] ?? $r['total'] ?? null;

            return [
                'serie'  => (string)($serie ?? ''),
                'numero' => (string)($numero ?? ''),
                'nombre' => (string)($nombre ?? 'SIN DATOS DEL CONDUCTOR'),
                'fecha'  => (string)($fecha ?? ''),
                'total'  => $total !== null ? (float)$total : 0.0,
            ];
        }, $rows);

        $totalOperacion = 0.0;
        foreach ($remisiones as $r) {
            $totalOperacion += (float)$r['total'];
        }

        foreach ($remisiones as $r) {
            $this->bitacora->bitacora(
                ip: $ip,
                usuario: $usuario,
                serie: (string)$r['serie'],
                remision: (string)$r['numero'],
                referencia: '',
                autorizacion: '',
                operacion: self::TIPO_OPERACION_BITACORA,
                totalOperacion: $totalOperacion,
                totalPago: (float)$r['total'],
                estatus: 'EXITOSO',
                codRespuesta: '000',
                tipoPlaca: $tipoPlaca,
                placa: $placa
            );
            $this->commitBitacora();
        }

        return [
            'remisiones' => $remisiones,
        ];
    }
}