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

        // Si no mandan remisiones
        if (count($remisiones) === 0) {
            return [
                'error' => [
                    'cod' => '004',
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

