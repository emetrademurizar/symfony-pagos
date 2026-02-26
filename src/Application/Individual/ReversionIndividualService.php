<?php

namespace App\Application\Individual;

class ReversionIndividualService
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
        // Mock igual que lo hicimos en consulta y pago (después conectás lógica real)
        if ($usuario !== 'demo' || $pass !== 'demo123') {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO Y/O CONTRASEÑA NO VALIDOS',
                ],
            ];
        }

        // Si no mandan remisiones, simulamos “no procesada”
        if (count($remisiones) === 0) {
            return [
                'reversion' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ],
            ];
        }

        // Agarramos la primera para armar DOC como en el manual (SERIE-REMISION)
        $r = $remisiones[0];
        $serie = (string)($r['serie'] ?? '');
        $remision = (string)($r['remision'] ?? '');

        $doc = trim($serie . '-' . $remision, '-');

        return [
            'reversion' => [
                'doc' => $doc,
                'cod' => '000',
                'mensaje' => 'REVERSION EXITOSA',
            ],
        ];
    }
}