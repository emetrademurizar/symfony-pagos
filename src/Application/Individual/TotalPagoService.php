<?php

namespace App\Application\Individual;

use App\Utils\Bitacora;
use App\Utils\Validator;
use Doctrine\DBAL\Connection;

class TotalPagoService
{
    private const TIPO_OPERA = 'N';
    private const TIPO_OPERACION_BITACORA = '5';

    public function __construct(
        private readonly Connection $conn,
        private readonly Validator $validator,
        private readonly Bitacora $bitacora,
    ) {}

    /**
     * @return array{
     *   error?: array{cod:string,mensaje:string},
     *   total_pago?: array{doc:string,cod:string,mensaje:string},
     *   procesadas?: array<int, array{serie:string,remision:string,documento:string,codigo:string,mensaje:string}>,
     *   no_procesadas?: array<int, array{serie:string,remision:string,codigo:string,mensaje:string}>
     * }
     */
    public function execute(
        string $tipoPlaca,
        string $placa,
        float|int|string $total,
        string $noReferencia,
        string $noAutorizacion,
        string $usuario,
        string $pass,
        string $ip = ''
    ): array {
        $tipoPlaca = strtoupper(trim($tipoPlaca));
        $placa = strtoupper(trim($placa));
        $noReferencia = trim($noReferencia);
        $noAutorizacion = trim($noAutorizacion);
        $totalCobrado = (float)$total;

        $userData = $this->validator->validUser($usuario, $pass);

        if (!$userData) {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO Y/O CONTRASEÑA NO VALIDOS',
                ],
            ];
        }

        $caja = $userData['CAJA'] ?? $userData['caja'] ?? '';

        if ($tipoPlaca === '' || !$this->validator->validPlaca($placa)) {
            return [
                'error' => [
                    'cod' => '002',
                    'mensaje' => 'LA PLACA NO ES VALIDA',
                ],
            ];
        }

        if ($noReferencia === '' || $noAutorizacion === '') {
            return [
                'total_pago' => [
                    'doc' => '',
                    'cod' => '007',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

        $sqlConsulta = <<<'SQL'
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
                COALESCE(r.agente_emitida, cp.agente) AS agente,
                CASE COALESCE(cp.estatus, r.status)
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
                CASE rt.serie
                    WHEN 'X' THEN REPLACE(COALESCE(rt.LUGAR, COALESCE(cp.lugar, c.descripcion)), '_', ' ')
                    WHEN 'W' THEN REPLACE(COALESCE(rt.LUGAR, ins.lugar), '_', ' ')
                    ELSE REPLACE(COALESCE(rt.LUGAR, r.lugar), '_', ' ') || '\nArticulos: ' ||
                         LISTAGG(TO_CHAR(ARTICULO) || ' - ' || TRIM(NUMERAL), ', ')
                         WITHIN GROUP (ORDER BY ARTICULO)
                END AS lugar,
                rt.NOMBRE,
                rt.FECHA_NOTIFICADA,
                LISTAGG(TO_CHAR(ARTICULO) || ' - ' || TRIM(NUMERAL), ', ')
                    WITHIN GROUP (ORDER BY ARTICULO) AS ARTICULOS
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
            $rows = $this->conn->fetchAllAssociative($sqlConsulta, [
                'tipo' => $tipoPlaca,
                'placa' => $placa,
            ]);
        } catch (\Throwable) {
            return [
                'total_pago' => [
                    'doc' => '',
                    'cod' => '007',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

        if (!$rows) {
            return [
                'error' => [
                    'cod' => '004',
                    'mensaje' => 'SIN REMISIONES PENDIENTES DE PAGO',
                ],
            ];
        }

        $remisiones = [];
        $totalPendiente = 0.0;

        foreach ($rows as $row) {
            $montoRemision = (float)($row['SALDO'] ?? $row['saldo'] ?? 0);

            $remisiones[] = [
                'serie' => (string)($row['SERIE'] ?? $row['serie'] ?? ''),
                'remision' => (string)($row['REMISION'] ?? $row['remision'] ?? ''),
                'total' => $montoRemision,
                'tipo_placa' => (string)($row['TIPO_PLACA'] ?? $row['tipo_placa'] ?? $tipoPlaca),
                'placa' => (string)($row['PLACA'] ?? $row['placa'] ?? $placa),
                'no_referencia' => $noReferencia,
                'no_autorizacion' => $noAutorizacion,
            ];

            $totalPendiente += $montoRemision;
        }

        if (round($totalPendiente, 2) !== round($totalCobrado, 2)) {
            return [
                'error' => [
                    'cod' => '003',
                    'mensaje' => 'EL PAGO NO ES EQUIVALENTE AL SALDO PENDIENTE',
                ],
            ];
        }


        $totalOperacion = 0.0;
        foreach ($remisiones as $r) {
            $totalOperacion += (float)($r['total'] ?? 0);
        }

        $oci = null;
        $documentoInicial = '';
        $ultimoDocumento = '';
        $detener = false;

        $procesadas = [];
        $noProcesadas = [];

        try {
            $oci = $this->conn->getNativeConnection();

            $sqlPago = <<<'SQL'
                BEGIN
                    admemetra.pkg_pago_servicios.sp_aplicar_pago(
                        :p_serie,
                        :p_remis,
                        :p_monto,
                        :p_tipo_opera,
                        :p_numero_recibo,
                        :p_documento_pagado,
                        :p_usuario_graba,
                        :nombre
                    );
                END;
            SQL;

            foreach ($remisiones as $index => $r) {
                $serie = strtoupper(trim((string)($r['serie'] ?? '')));
                $remision = trim((string)($r['remision'] ?? ''));
                $monto = (float)($r['total'] ?? 0);
                $tipoPlacaRem = strtoupper(trim((string)($r['tipo_placa'] ?? '')));
                $placaRem = strtoupper(trim((string)($r['placa'] ?? '')));
                $referencia = trim((string)($r['no_referencia'] ?? ''));
                $autorizacion = trim((string)($r['no_autorizacion'] ?? ''));
                $nombre = '';

                if ($detener) {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: $totalOperacion,
                        totalPago: $monto,
                        estatus: 'ERROR',
                        codRespuesta: '007',
                        tipoPlaca: $tipoPlacaRem,
                        placa: $placaRem
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '007',
                        'mensaje' => 'NO PROCESADA POR FALLO EN TRANSACCION ANTERIOR',
                    ];

                    continue;
                }

                if ($serie === '' || $remision === '' || $monto <= 0) {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: $totalOperacion,
                        totalPago: $monto,
                        estatus: 'ERROR',
                        codRespuesta: '007',
                        tipoPlaca: $tipoPlacaRem,
                        placa: $placaRem
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '007',
                        'mensaje' => 'DATOS DE REMISION INVALIDOS',
                    ];

                    $detener = true;
                    continue;
                }

                if($referencia === '' || $autorizacion === '') {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: $totalOperacion,
                        totalPago: $monto,
                        estatus: 'ERROR',
                        codRespuesta: '007',
                        tipoPlaca: $tipoPlacaRem,
                        placa: $placaRem
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '007',
                        'mensaje' => 'TRANSACCION NO PROCESADA: REFERENCIA Y/O AUTORIZACION INVALIDAS',
                    ];

                    $detener = true;
                    continue;
                }

                try{
                    if($this->bitacora->existeTransaccion($serie, $remision, $referencia, $autorizacion)) {
                        $this->bitacora->bitacora(
                            ip: $ip,
                            usuario: $usuario,
                            serie: $serie,
                            remision: $remision,
                            referencia: $referencia,
                            autorizacion: $autorizacion,
                            operacion: self::TIPO_OPERACION_BITACORA,
                            totalOperacion: $totalOperacion,
                            totalPago: $monto,
                            estatus: 'ERROR',
                            codRespuesta: '005',
                            tipoPlaca: $tipoPlacaRem,
                            placa: $placaRem
                        );

                        $noProcesadas[] = [
                            'serie' => $serie,
                            'remision' => $remision,
                            'codigo' => '005',
                            'mensaje' => 'DATOS DE TRANSACCION PROCESADOS CON ANTERIORIDAD',
                        ];

                        $detener = true;
                        continue;
                    }
                }catch(\Throwable $e) {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: $totalOperacion,
                        totalPago: $monto,
                        estatus: 'ERROR',
                        codRespuesta: '007',
                        tipoPlaca: $tipoPlacaRem,
                        placa: $placaRem
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '007',
                        'mensaje' => 'TRANSACCION NOPROCESADA',
                    ];

                    $detener = true;
                    continue;
                }

                $numeroRecibo = $index === 0 ? '0' : $documentoInicial;

                $stmtPago = oci_parse($oci, $sqlPago);
                if ($stmtPago === false) {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: $totalOperacion,
                        totalPago: $monto,
                        estatus: 'ERROR',
                        codRespuesta: '007',
                        tipoPlaca: $tipoPlacaRem,
                        placa: $placaRem
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '007',
                        'mensaje' => 'NO SE PUDO PREPARAR LA SENTENCIA DE PAGO',
                    ];

                    $detener = true;
                    continue;
                }

                $tipoOpera = self::TIPO_OPERA;
                $usuarioGraba = $caja;
                $documentoSalida = '';

                oci_bind_by_name($stmtPago, ':p_serie', $serie);
                oci_bind_by_name($stmtPago, ':p_remis', $remision);
                oci_bind_by_name($stmtPago, ':p_monto', $monto);
                oci_bind_by_name($stmtPago, ':p_tipo_opera', $tipoOpera);
                oci_bind_by_name($stmtPago, ':p_numero_recibo', $numeroRecibo, 4000);
                oci_bind_by_name($stmtPago, ':p_documento_pagado', $documentoSalida, 4000);
                oci_bind_by_name($stmtPago, ':p_usuario_graba', $usuarioGraba);
                oci_bind_by_name($stmtPago, ':nombre', $nombre, 4000);

                $okPago = oci_execute($stmtPago, OCI_NO_AUTO_COMMIT);

                if ($okPago === false) {
                    $e = oci_error($stmtPago) ?: oci_error($oci);
                    oci_free_statement($stmtPago);

                    $mensaje = $e['message'] ?? 'TRANSACCION NO PROCESADA';

                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: $totalOperacion,
                        totalPago: $monto,
                        estatus: 'ERROR',
                        codRespuesta: '007',
                        tipoPlaca: $tipoPlacaRem,
                        placa: $placaRem
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '007',
                        'mensaje' => $mensaje,
                    ];

                    $detener = true;
                    continue;
                }

                oci_free_statement($stmtPago);

                $documentoSalida = trim($documentoSalida);
                $ultimoDocumento = $documentoSalida;

                $esNumeroDocumento = ctype_digit($documentoSalida);

                if ($index === 0 && $esNumeroDocumento) {
                    $documentoInicial = $documentoSalida;
                }

                if ($esNumeroDocumento) {
                    $codigoRespuesta = '000';
                    $estatus = 'EXITOSO';
                    $mensajeRespuesta = 'REMISION PAGADA';

                    $procesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'documento' => $documentoSalida,
                        'codigo' => '000',
                        'mensaje' => $mensajeRespuesta,
                    ];
                } elseif (str_contains(strtoupper($documentoSalida), 'YA FUE PAGADA')) {
                    $codigoRespuesta = '006';
                    $estatus = 'ERROR';
                    $mensajeRespuesta = 'LA REMISION YA FUE PAGADA';

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '006',
                        'mensaje' => $mensajeRespuesta,
                    ];

                    $detener = true;
                } elseif (
                    str_contains(strtoupper($documentoSalida), 'ANTERIORIDAD') ||
                    str_contains(strtoupper($documentoSalida), 'PROCESADOS CON ANTERIORIDAD')
                ) {
                    $codigoRespuesta = '005';
                    $estatus = 'ERROR';
                    $mensajeRespuesta = 'DATOS DE TRANSACCION PROCESADOS CON ANTERIORIDAD';

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '005',
                        'mensaje' => $mensajeRespuesta,
                    ];

                    $detener = true;
                } else {
                    $codigoRespuesta = '007';
                    $estatus = 'ERROR';
                    $mensajeRespuesta = 'TRANSACCION NO PROCESADA';

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '007',
                        'mensaje' => $mensajeRespuesta,
                    ];

                    $detener = true;
                }

                $this->bitacora->bitacora(
                    ip: $ip,
                    usuario: $usuario,
                    serie: $serie,
                    remision: $remision,
                    referencia: $referencia,
                    autorizacion: $autorizacion,
                    operacion: self::TIPO_OPERACION_BITACORA,
                    totalOperacion: $totalOperacion,
                    totalPago: $monto,
                    estatus: $estatus,
                    codRespuesta: $codigoRespuesta,
                    tipoPlaca: $tipoPlacaRem,
                    placa: $placaRem,
                    doc: $documentoSalida,
                );
            }

            if (!oci_commit($oci)) {
                $e = oci_error($oci);
                throw new \RuntimeException($e['message'] ?? 'ERROR AL CONFIRMAR PAGO');
            }

            if (count($procesadas) > 0 && count($noProcesadas) > 0) {
                return [
                    'total_pago' => [
                        'doc' => '',
                        'cod' => '007',
                        'mensaje' => 'TRANSACCION NO PROCESADA',
                    ],
                    'procesadas' => $procesadas,
                    'no_procesadas' => $noProcesadas,
                ];
            }

            if (count($noProcesadas) > 0) {
                $primeraNoProcesada = $noProcesadas[0];

                return [
                    'total_pago' => [
                        'doc' => '',
                        'cod' => $primeraNoProcesada['codigo'],
                        'mensaje' => $primeraNoProcesada['mensaje'],
                    ],
                ];
            }

            return [
                'total_pago' => [
                    'doc' => $documentoInicial !== '' ? $documentoInicial : $ultimoDocumento,
                    'cod' => '000',
                    'mensaje' => 'REMISION PAGADA',
                ],
            ];
        } catch (\Throwable) {
            if ($oci) {
                @oci_rollback($oci);
            }

            return [
                'total_pago' => [
                    'doc' => '',
                    'cod' => '007',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }
    }
}