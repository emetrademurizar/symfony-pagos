<?php

namespace App\Application\Individual;

class ConsultaIndividualService
{
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

        // 3) Simular sin remisiones
        if ($placa === '000000') {
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
                    'total' => 100.00,
                ],
            ],
        ];
    }
}
