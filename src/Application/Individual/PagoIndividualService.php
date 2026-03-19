<?php

namespace App\Application\Individual;

use App\Utils\Bitacora;
use App\Utils\Validator;
use Doctrine\DBAL\Connection;

class PagoIndividualService
{
    private const TIPO_OPERA = 'N';
    private const TIPO_OPERACION_BITACORA = '2';

    public function __construct(
        private readonly Connection $conn,
        private readonly Validator $validator,
        private readonly Bitacora $bitacora,
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
    public function execute(array $remisiones, string $usuario, string $pass, string $ip = ''): array
    {

        $userData = $this->validator->validUser($usuario, $pass);

        if (!$userData) {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO Y/O CONTRASEÑA NO VALIDOS',
                ],
            ];
        }

        $codigoUsuario = $userData['codigo'];
        $nombreUsuario = $userData['nombre_banco'] ?? '';

        if (count($remisiones) === 0) {
            return [
                'remision' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

        $primera = $remisiones[0];
        $noReferencia = trim((string)($primera['no_referencia'] ?? ''));
        $noAutorizacion = trim((string)($primera['no_autorizacion'] ?? ''));

        if ($noReferencia === '' || $noAutorizacion === '') {
            return [
                'remision' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

        try {
            if ($this->bitacora->existeTransaccion($noReferencia, $noAutorizacion)) {
                return [
                    'remision' => [
                        'doc' => '',
                        'cod' => '002',
                        'mensaje' => 'DATOS DE TRANSACCON PROCESADOS CON ANTERIORIDAD',
                    ],
                ];
            }
        } catch (\Throwable) {
            return [
                'remision' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

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

            foreach ($remisiones as $index => $r) {
                $serie = strtoupper(trim((string)($r['serie'] ?? '')));
                $remision = trim((string)($r['remision'] ?? $r['numero'] ?? ''));
                $monto = (float)($r['total'] ?? $r['valor'] ?? 0);
                $tipoPlaca = strtoupper(trim((string)($r['tipo_placa'] ?? '')));
                $placa = strtoupper(trim((string)($r['placa'] ?? '')));
                $referencia = trim((string)($r['no_referencia'] ?? ''));
                $autorizacion = trim((string)($r['no_autorizacion'] ?? ''));
                $nombre = '';

                // Si ya hubo un error antes, esta remisión ya no se intenta pagar.
                if ($detener) {
                    $this->bitacora->bitacora(
                        codigo: $codigoUsuario,
                        ip: $ip,
                        usuario: $nombreUsuario,
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
                        'mensaje' => 'NO PROCESADA POR FALLO EN TRANSACCION ANTERIOR',
                    ];

                    continue;
                }

                if ($serie === '' || $remision === '' || $monto <= 0) {
                    $this->bitacora->bitacora(
                        codigo: $codigoUsuario,
                        ip: $ip,
                        usuario: $nombreUsuario,
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
                        'mensaje' => 'DATOS DE REMISION INVALIDOS',
                    ];

                    $detener = true;
                    continue;
                }

                $numeroRecibo = $index === 0 ? '0' : $documentoInicial;

                $stmtPago = oci_parse($oci, $sqlPago);
                if ($stmtPago === false) {
                    $this->bitacora->bitacora(
                        codigo: $codigoUsuario,
                        ip: $ip,
                        usuario: $nombreUsuario,
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
                $usuarioGraba = $nombreUsuario;
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
                        codigo: $codigoUsuario,
                        ip: $ip,
                        usuario: $nombreUsuario,
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
                } else {
                    $codigoRespuesta = '004';
                    $estatus = 'ERROR';
                    $mensajeRespuesta = $documentoSalida !== '' ? $documentoSalida : 'TRANSACCION NO PROCESADA';

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
                        'mensaje' => $mensajeRespuesta,
                    ];

                    $detener = true;
                }
                
                /* elseif (
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
                    $mensajeRespuesta = 'TRANSACCION NO PROCESADA';

                    $noProcesadas[] = [
                        'serie' => $serie,
                        'remision' => $remision,
                        'codigo' => '004',
                        'mensaje' => $mensajeRespuesta,
                    ];

                    $detener = true;
                }*/

                // Bitácora siempre
                $this->bitacora->bitacora(
                    codigo: $codigoUsuario,
                    ip: $ip,
                    usuario: $nombreUsuario,
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
                    placa: $placa
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
                        'mensaje' => 'TRANSACCION NO PROCESADA',
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
        } catch (\Throwable) {
            if ($oci) {
                @oci_rollback($oci);
            }

            return [
                'remision' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }
    }
}