<?php

namespace App\Application\Individual;

class TotalPagoService
{
    /**
     * @param array<int, array{
     *   serie?: string,
     *   remision?: string,
     *   total?: float|int|string,
     *   no_referencia?: int|string,
     *   no_autorizacion?: int|string
     * }> $remisiones
     */
    public function execute(array $remisiones, string $usuario, string $pass): array
    {
        // Mock de validación (puedes conectarlo con la lógica real de validación de usuario y clave)
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

        $is_total = true;
        if(!$is_total) {
            return [
                'error' => [
                    'cod' => '003',
                    'mensaje' => 'EL PAGO NO ES EQUIVALENTE AL SALDO PENDIENTE',
                ],
            ];
        }

        // Validar si las remisiones están vacías
        if (count($remisiones) === 0) {
            return [
                'error' => [
                    'cod' => '004',
                    'mensaje' => 'SIN REMISIONES PENDIENTES DE PAGO',
                ],
            ];
        }

        $anterior = false;
        if($anterior) {
            return [
                'total_pago' => [
                    'doc' => 'T-2142',
                    'cod' => '005',
                    'mensaje' => 'DATOS DE TRANSACCION PROCESADOS CON ANTERIORIDAD',
                ],
            ];
        }

        $pagada = false;
        if($pagada) {
            return [
                'total_pago' => [
                    'doc' => 'T-2142',
                    'cod' => '006',
                    'mensaje' => 'REMISION YA FUE PAGADA',
                ],
            ];
        }

        $no_procesada = false;
        if($no_procesada) {
            return [
                'total_pago' => [
                    'doc' => 'T-2142',
                    'cod' => '007',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }
        // Calcular el total de la remisión
        $total = 0;
        foreach ($remisiones as $remision) {
            $total += (float) ($remision['total'] ?? 0); // Sumar el total de todas las remisiones
        }

        return [
            'total_pago' => [
                'doc' => 'T-2142',
                'cod' => '000',
                'mensaje' => 'REMISION PAGADA',
            ],
        ];
    }
}