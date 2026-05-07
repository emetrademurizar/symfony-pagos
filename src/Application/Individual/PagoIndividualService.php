<?php

namespace App\Application\Individual;

use App\Utils\Bitacora;
use App\Utils\Validator;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;


class PagoIndividualService
{
    private const TIPO_OPERA = 'W';
    private const TIPO_OPERACION_BITACORA = '2';

    public function __construct(
        private readonly Connection $conn,
        private readonly Validator $validator,
        private readonly Bitacora $bitacora,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<int, array{
     *   serie?: string,
     *   remision?: string,
     *   numero?: string,
     *   total?: float|int|string,
     *   valor?: float|int|string,
     *   no_referencia?: int|string,
     *   no_autorizacion?: int|string,
     *   tipo_placa?: string,
     *   placa?: string
     * }> $remisiones
     */
    public function execute(array $remisiones, string $usuario, string $ip = ''): array
    {

        // $userData = $this->validator->validUser($usuario, $pass);
        $userData = $this->validator->getInfoUser($usuario);
        
        $this->logger->info('Solicitud usuario recibida', [
            'user'    => $usuario,
            'data'    => $userData,
        ]);

        $caja = $userData['caja'] ?? '';
        $nombreBanco = $userData['bank_name'] . ' ' . $userData['environment'];

        if (count($remisiones) === 0) {
            return [
                'remision' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA malas remisiones',
                ],
            ];
        }

        $this->logger->info('Iniciando proceso de pago individual', [
            'usuario' => $usuario,
            'ip' => $ip,
            'remisiones' => $remisiones,
        ]);

        $totalOperacion = 0.0;
        foreach ($remisiones as $r) {
            $totalOperacion += (float)($r['total'] ?? $r['valor'] ?? 0);
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
            
            $primeraRemision = $remisiones[0] ?? [];
            $seriePrimera = strtoupper(trim((string)($primeraRemision['serie'] ?? '')));
            $remisionPrimera = trim((string)($primeraRemision['remision'] ?? $primeraRemision['numero'] ?? ''));

            if ($seriePrimera === '' || $remisionPrimera === '') {
                return [
                    'remision' => [
                        'doc' => '',
                        'cod' => '004',
                        'mensaje' => 'TRANSACCION NO PROCESADA: PRIMERA REMISION INVALIDA',
                    ],
                ];
            }

            $infoPlaca = $this->bitacora->obtenerPlacaPorRemision($seriePrimera, $remisionPrimera);

            if (
                !$infoPlaca ||
                empty($infoPlaca['tipo_placa']) ||
                empty($infoPlaca['placa'])
            ) {
                return [
                    'remision' => [
                        'doc' => '',
                        'cod' => '004',
                        'mensaje' => 'TRANSACCION NO PROCESADA: NO SE ENCONTRO PLACA PARA LA REMISION',
                    ],
                ];
            }

            $tipoPlaca = $infoPlaca['tipo_placa'];
            $placa = $infoPlaca['placa'];

            $this->logger->info('Placa obtenida para pago individual', [
                'serie' => $seriePrimera,
                'remision' => $remisionPrimera,
                'tipo_placa' => $tipoPlaca,
                'placa' => $placa,
            ]);

            foreach ($remisiones as $index => $r) {
                $serie = strtoupper(trim((string)($r['serie'] ?? '')));
                $remision = trim((string)($r['remision'] ?? $r['numero'] ?? ''));
                $monto = (float)($r['total'] ?? $r['valor'] ?? 0);
                $referencia = trim((string)($r['no_referencia'] ?? ''));
                $autorizacion = trim((string)($r['no_autorizacion'] ?? ''));
                $nombre = '';

                $this->logger->info('Procesando remisión', [
                    'serie' => $serie,
                    'remision' => $remision,
                    'monto' => $monto,
                    'referencia' => $referencia,
                    'autorizacion' => $autorizacion,
                    'tipo_placa' => $tipoPlaca,
                    'placa' => $placa,
                ]);

                // Si ya hubo un error antes, esta remisión ya no se intenta pagar.
                if ($detener) {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        nombreBanco: $nombreBanco,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: (float)$totalOperacion,
                        totalPago: (float)$monto,
                        estatus: 'ERROR',
                        codRespuesta: '004',
                        tipoPlaca: $tipoPlaca,
                        placa: $placa,
                        comentarios: 'NO PROCESADA POR FALLO ANTERIOR'
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
                        'mensaje' => 'NO PROCESADA POR FALLO EN TRANSACCION ANTERIOR',
                    ];

                    continue;
                }

                if ($serie === '' || $remision === '' || $monto <= 0) {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        nombreBanco: $nombreBanco,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: (float)$totalOperacion,
                        totalPago: (float)$monto,
                        estatus: 'ERROR',
                        codRespuesta: '004',
                        tipoPlaca: $tipoPlaca,
                        placa: $placa,
                        comentarios: 'DATOS DE REMISION INVALIDOS'
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
                        'mensaje' => 'DATOS DE REMISION INVALIDOS',
                    ];

                    $detener = true;
                    continue;
                }

                if ($referencia === '' || $autorizacion === '') {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        nombreBanco: $nombreBanco,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: (float)$totalOperacion,
                        totalPago: (float)$monto,
                        estatus: 'ERROR',
                        codRespuesta: '004',
                        tipoPlaca: $tipoPlaca,
                        placa: $placa,
                        comentarios: 'NO HAY REFERENCIA O AUTORIZACION'
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
                        'mensaje' => 'TRANSACCION NO PROCESADA NO HAY REFERENCIA O AUTORIZACION',
                    ];

                    $detener = true;
                    continue;
                }
                
                try{
                    if ($this->bitacora->existeTransaccion($serie, $remision, $referencia, $autorizacion)) {
                        $this->bitacora->bitacora(
                            ip: $ip,
                            usuario: $usuario,
                            nombreBanco: $nombreBanco,
                            serie: $serie,
                            remision: $remision,
                            referencia: $referencia,
                            autorizacion: $autorizacion,
                            operacion: self::TIPO_OPERACION_BITACORA,
                            totalOperacion: (float)$totalOperacion,
                            totalPago: (float)$monto,
                            estatus: 'ERROR',
                            codRespuesta: '002',
                            tipoPlaca: $tipoPlaca,
                            placa: $placa,
                            comentarios: 'DATOS DE TRANSACCON PROCESADOS CON ANTERIORIDAD',
                        );

                        $noProcesadas[] = [
                            'serie' => $serie,
                            'remision' => $remision,
                            'codigo' => '002',
                            'mensaje' => 'DATOS DE TRANSACCON PROCESADOS CON ANTERIORIDAD',
                        ];

                        $detener = true;
                        continue;
                    }
                } catch (\Throwable) {
                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        nombreBanco: $nombreBanco,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: (float)$totalOperacion,
                        totalPago: (float)$monto,
                        estatus: 'ERROR',
                        codRespuesta: '004',
                        tipoPlaca: $tipoPlaca,
                        placa: $placa,
                        comentarios: 'ERROR AL PROCESAR TRANSACCION'
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
                        'mensaje' => 'TRANSACCION NO PROCESADA 2',
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
                        nombreBanco: $nombreBanco,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: (float)$totalOperacion,
                        totalPago: (float)$monto,
                        estatus: 'ERROR',
                        codRespuesta: '004',
                        tipoPlaca: $tipoPlaca,
                        placa: $placa
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
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
                oci_bind_by_name($stmtPago, ':p_numero_recibo', $numeroRecibo);
                oci_bind_by_name($stmtPago, ':p_documento_pagado', $documentoSalida, 6000, SQLT_CHR);
                oci_bind_by_name($stmtPago, ':p_usuario_graba', $usuarioGraba);
                oci_bind_by_name($stmtPago, ':nombre', $nombre);

                $this->logger->info('Ejecutando sp_aplicar_pago', [
                    'sql' => $sqlPago,
                    'params' => [
                        'p_serie' => $serie,
                        'p_remis' => $remision,
                        'p_monto' => $monto,
                        'p_tipo_opera' => $tipoOpera,
                        'p_numero_recibo' => $numeroRecibo,
                        'p_usuario_graba' => $usuarioGraba,
                        'p_nombre_pago' => $nombre,
                    ],
                ]);
                
                $okPago = oci_execute($stmtPago, OCI_NO_AUTO_COMMIT);

                $this->logger->info('Respuesta sp_aplicar_pago', [
                    'okPago' => $okPago,
                    'salida' => [
                        'p_documento_pagado' => $documentoSalida,
                    ],
                ]);
                if ($okPago === false) {
                    $e = oci_error($stmtPago) ?: oci_error($oci);
                    oci_free_statement($stmtPago);

                    $mensaje = $e['message'] ?? 'TRANSACCION NO PROCESADA 3';

                    $this->bitacora->bitacora(
                        ip: $ip,
                        usuario: $usuario,
                        nombreBanco: $nombreBanco,
                        serie: $serie,
                        remision: $remision,
                        referencia: $referencia,
                        autorizacion: $autorizacion,
                        operacion: self::TIPO_OPERACION_BITACORA,
                        totalOperacion: (float)$totalOperacion,
                        totalPago: (float)$monto,
                        estatus: 'ERROR',
                        codRespuesta: '004',
                        tipoPlaca: $tipoPlaca,
                        placa: $placa,
                        comentarios: 'ERROR AL PROCESAR TRANSACCION: ' . $mensaje
                    );

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
                        'mensaje' => $mensaje,
                    ];

                    $detener = true;
                    continue;
                }

                oci_free_statement($stmtPago);

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
                } elseif (str_contains(strtoupper($documentoSalida), 'SE ENCUENTRA PAGADA')) {
                    $codigoRespuesta = '003';
                    $estatus = 'ERROR';
                    $mensajeRespuesta = 'LA REMISION YA FUE PAGADA';

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '003',
                        'mensaje' => $mensajeRespuesta,
                    ];

                    $detener = true;
                }  elseif (
                    str_contains(strtoupper($documentoSalida), 'ANTERIORIDAD') ||
                    str_contains(strtoupper($documentoSalida), 'PROCESADOS CON ANTERIORIDAD')
                ) {
                    $codigoRespuesta = '002';
                    $estatus = 'ERROR';
                    $mensajeRespuesta = 'DATOS DE TRANSACCON PROCESADOS CON ANTERIORIDAD';

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '002',
                        'mensaje' => $mensajeRespuesta,
                    ];

                    $detener = true;
                } else {
                    $codigoRespuesta = '004';
                    $estatus = 'ERROR';
                    $mensajeRespuesta = 'TRANSACCION NO PROCESADA 5';

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
                        'mensaje' => $mensajeRespuesta,
                    ];

                    $detener = true;
                }


                // Preparar valores seguros para bitácora
                $docBitacora = '';
                $comentarioBitacora = $mensajeRespuesta;

                $documentoJson = json_decode($documentoSalida, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($documentoJson)) {
                    $docBitacora = (string)($documentoJson['numeroRecibo'] ?? '');
                    $comentarioBitacora = (string)($documentoJson['respuesta'] ?? $mensajeRespuesta);
                } elseif (ctype_digit($documentoSalida)) {
                    $docBitacora = $documentoSalida;
                    $comentarioBitacora = $mensajeRespuesta;
                } else {
                    $docBitacora = '';
                    $comentarioBitacora = $documentoSalida !== ''
                        ? $documentoSalida
                        : $mensajeRespuesta;
                }

                // Bitácora siempre
                $this->bitacora->bitacora(
                    ip: $ip,
                    usuario: $usuario,
                    nombreBanco: $nombreBanco,
                    serie: $serie,
                    remision: $remision,
                    referencia: $referencia,
                    autorizacion: $autorizacion,
                    operacion: self::TIPO_OPERACION_BITACORA,
                    totalOperacion: (float)$totalOperacion,
                    totalPago: (float)$monto,
                    estatus: $estatus,
                    codRespuesta: $codigoRespuesta,
                    tipoPlaca: $tipoPlaca,
                    placa: $placa,
                    doc: $docBitacora,
                    comentarios: $comentarioBitacora
                );
            }

            if (!oci_commit($oci)) {
                $e = oci_error($oci);
                throw new \RuntimeException($e['message'] ?? 'ERROR AL CONFIRMAR PAGO');
            }

            if (count($procesadas) > 0 && count($noProcesadas) > 0) {
                return [
                    'remision' => [
                        'doc' => '',
                        'cod' => '004',
                        'mensaje' => 'TRANSACCION NO PROCESADA 6',
                    ],
                    'procesadas' => $procesadas,
                    'no_procesadas' => $noProcesadas,
                ];
            }

            if (count($noProcesadas) > 0) {
                $primeraNoProcesada = $noProcesadas[0];

                return [
                    'remision' => [
                        'doc' => '',
                        'cod' => $primeraNoProcesada['codigo'],
                        'mensaje' => $primeraNoProcesada['mensaje'],
                    ],
                ];
            }

            return [
                'remision' => [
                    'doc' => $documentoInicial !== '' ? $documentoInicial : $ultimoDocumento,
                    'cod' => '000',
                    'mensaje' => 'REMISION PAGADA',
                ],
            ];
        } catch (\Throwable $e) {
            if ($oci) {
                @oci_rollback($oci);
            }

            return [
                'remision' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA 7: ' . ($e->getMessage() ?? ''),
                ],
            ];
        }
    }
}