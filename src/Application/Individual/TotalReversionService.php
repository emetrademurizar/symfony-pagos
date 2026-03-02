<?php

namespace App\Application\Individual;

class TotalReversionService
{
    /**
     * @param array<int, array{
     *   tipoPlaca?: string,
     *   placa?: string,
     *   total?: float|int|string,
     *   no_referencia?: int|string,
     *   no_autorizacion?: int|string
     * }> $remisiones
     */
    public function execute(array $remisiones, string $usuario, string $pass): array
    {
        // Mock de validación
        if ($usuario !== 'demo' || $pass !== 'demo123') {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO Y/O CONTRASEÑA NO VALIDOS',
                ],
            ];
        }

        $placa_valida = true;
        if(!$placa_valida) {        
            return [
                'error' => [
                    'cod' => '002',
                    'mensaje' => 'LA PLACA NO ES VALIDA',
                ],
            ];
        }

        // Si no mandan remisiones
        if (count($remisiones) === 0) {
            return [
                'remision' => [
                    'doc' => 'T-2142',
                    'cod' => '003',
                    'mensaje' => 'SIN REMISIONES PENDIENTES',
                ],
            ];
        }

        $fecha_valida = true;
        if (!$fecha_valida) {
            return [
                'remision' => [
                    'doc' => 'T-2142',
                    'cod' => '004',
                    'mensaje' => 'SE HA EXCEDIDO LA FECHA',
                ],
            ];
        }

        $pagada = true;
        if (!$pagada) {
            return [
                'remision' => [
                    'doc' => 'T-2142',
                    'cod' => '005',
                    'mensaje' => 'REMISION SIN PAGAR',
                ],
            ];
        }

        $procesada = true;
        if (!$procesada) {
            return [
                'remision' => [
                    'doc' => 'T-2142',
                    'cod' => '006',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

        // DOC como ejemplo del manual: tipoPlaca-placa (ej: T-2142)
        $r = $remisiones[0];
        $tipoPlaca = (string)($r['tipoPlaca'] ?? '');
        $placa     = (string)($r['placa'] ?? '');

        $doc = trim($tipoPlaca . '-' . $placa, '-');

        return [
            'remision' => [
                'doc' => $doc,
                'cod' => '000',
                'mensaje' => 'REMISION REVERSADA',
            ],
        ];
    }
}

