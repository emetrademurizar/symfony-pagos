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

        // Validar si las remisiones están vacías
        if (count($remisiones) === 0) {
            return [
                'error' => [
                    'cod' => '004',
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
                'cod' => '000',
                'mensaje' => 'REMISION PAGADA',
                'total' => $total,
            ],
        ];
    }
}