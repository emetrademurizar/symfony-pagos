<?php

namespace App\Application\Individual;
use Doctrine\DBAL\Connection;

class PagoIndividualService
{
    private const TIPO_OPERA = 'N';
    private const USUARIO_GRABA = 'POSNEONET';
    public function __construct(
        private readonly Connection $conn,
    ){}
    /**
     * @param array<int, array{
     *   serie?: string,
     *   remision?: string,
     *   numero?: string,
     *   total?: float|int|string,
     *   valor?: float|int|string,
     *   no_referencia?: int|string,
     *   no_autorizacion?: int|string
     * }> $remisiones
     */
    public function execute(array $remisiones, string $usuario, string $pass): array
    {
        // Mock igual que lo hicimos en consulta (después conectás lógica real)
        if ($usuario !== 'demo' || $pass !== 'demo123') {
            return [
                'error' => [
                    'cod' => '001',
                    'mensaje' => 'USUARIO Y/O CONTRASEÑA NO VALIDOS',
                ],
            ];
        }

        if (count($remisiones) === 0){
            return[
                'remision' => [
                    'doc' => '',
                    'cod' => '004',
                    'mensaje' => 'TRANSACCION NO PROCESADA',
                ]
            ];
        }

        $docs =[];

        

        $anterior = false;
        if ($anterior) {
            return [
                'remision' => [
                    'doc' => 'T-2142',
                    'cod' => '002',
                    'mensaje' => 'DATOS DE TRANSACCON PROCESADOS CON ANTERIORIDAD',
                ],
            ];
        }

        $pagada = false;
        if ($pagada) {
            return [
                'remision' => [
                    'doc' => 'T-2142',
                    'cod' => '003',
                    'mensaje' => 'LA REMISION YA FUE PAGADA',
                ],
            ];
        }

        // Si no mandan remisiones, simulamos “no procesada”
        if (count($remisiones) === 0) {
            return [
                'remision' => [
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
            'remision' => [
                'doc' => $doc,
                'cod' => '000',
                'mensaje' => 'REMISION PAGADA',
            ],
        ];
    }
}