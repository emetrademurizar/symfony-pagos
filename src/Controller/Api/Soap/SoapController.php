<?php

namespace App\Controller\Api\Soap;

use App\Application\Individual\ConsultaIndividualService;
use App\Application\Individual\PagoIndividualService;
use App\Application\Individual\ReversionIndividualService;
use App\Application\Individual\TotalConsultaService;
use App\Application\Individual\TotalPagoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SoapController extends AbstractController
{
    #[Route('/api/soap', methods: ['POST'])]
    public function handle(
        Request $request, 
        ConsultaIndividualService $consultaService,
        PagoIndividualService $pagoService,
        ReversionIndividualService $ReversionService,
        TotalConsultaService $TotalConsultaService,
        TotalPagoService $TotalPagoService
    ): Response{
        $raw = $request->getContent() ?? '';
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // quitar BOM si existe
        $raw = trim($raw);

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $ok = $dom->loadXML($raw);

        if (!$ok) {
            libxml_clear_errors();
            return new Response(
                '<ERROR><COD>999</COD><MENSAJE>XML INVALIDO</MENSAJE></ERROR>',
                400,
                ['Content-Type' => 'text/xml; charset=UTF-8']
            );
        }

        $xpath = new \DOMXPath($dom);

        // SOAP 1.1 estándar:
        $xpath->registerNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
        // SOAP 1.2 (por si acaso algún cliente manda esto):
        $xpath->registerNamespace('soap12', 'http://www.w3.org/2003/05/soap-envelope');

        // Tomamos el primer hijo dentro del Body (debería ser <consulta>)
        $opNode = $xpath->query('//soapenv:Body/*[1]')->item(0)
            ?? $xpath->query('//soap12:Body/*[1]')->item(0);

        if (!$opNode instanceof \DOMElement) {
            return $this->soapWrap(
                '<ERROR><COD>999</COD><MENSAJE>SOAP BODY NO ENCONTRADO</MENSAJE></ERROR>',
                400
            );
        }

        $opName = strtolower($opNode->localName ?? $opNode->nodeName);

        // =========================
        // (1) Consulta
        // =========================
        if ($opName === 'consulta') {
            $tipoPlaca = trim($xpath->evaluate('string(./Tipo_Placa)', $opNode));
            $placa     = trim($xpath->evaluate('string(./Placa)', $opNode));
            $usuario   = trim($xpath->evaluate('string(./Usuario)', $opNode));
            $pass      = trim($xpath->evaluate('string(./Pass)', $opNode));

            $result = $consultaService->execute($tipoPlaca, $placa, $usuario, $pass);

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string)$result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string)$result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';
            } else {
                $out = '<REMISIONES>';
                foreach (($result['remisiones'] ?? []) as $r) {
                    $out .= '<REMISION>'
                        . '<SERIE>' . htmlspecialchars((string)($r['serie'] ?? '')) . '</SERIE>'
                        . '<NUMERO>' . htmlspecialchars((string)($r['numero'] ?? '')) . '</NUMERO>'
                        . '<NOMBRE>' . htmlspecialchars((string)($r['nombre'] ?? '')) . '</NOMBRE>'
                        . '<FECHA>' . htmlspecialchars((string)($r['fecha'] ?? '')) . '</FECHA>'
                        . '<TOTAL>' . htmlspecialchars((string)($r['total'] ?? '')) . '</TOTAL>'
                        . '</REMISION>';
                }
                $out .= '</REMISIONES>';
            }

            return $this->soapWrap($out, 200);
        } 

        // =========================
        // (2) PAGO INDIVIDUAL
        // =========================
        if ($opName === 'pago') {
            // Usuario / Pass al mismo nivel que Remisiones (según lo que definamos)
            $usuario = trim($xpath->evaluate('string(./Usuario)', $opNode));
            $pass    = trim($xpath->evaluate('string(./Pass)', $opNode));

            $remisiones = [];

            // Caso 1: <Remisiones><Remision>...</Remision></Remisiones>
            $remNodes = $xpath->query('./Remisiones/Remision', $opNode);
            if ($remNodes && $remNodes->length > 0) {
                foreach ($remNodes as $rNode) {
                    if (!$rNode instanceof \DOMElement) continue;

                    $remisiones[] = [
                        'serie'          => trim($xpath->evaluate('string(./serie)', $rNode)),
                        'remision'       => trim($xpath->evaluate('string(./remision)', $rNode)),
                        'total'          => trim($xpath->evaluate('string(./total)', $rNode)),
                        'no_referencia'  => trim($xpath->evaluate('string(./no_referencia)', $rNode)),
                        'no_autorizacion'=> trim($xpath->evaluate('string(./no_autorizacion)', $rNode)),
                    ];
                }
            } else {
                // Caso 2 fallback: <Remision> directo dentro de <pago>
                $remNodes2 = $xpath->query('./Remision', $opNode);
                if ($remNodes2 && $remNodes2->length > 0) {
                    foreach ($remNodes2 as $rNode) {
                        if (!$rNode instanceof \DOMElement) continue;

                        $remisiones[] = [
                            'serie'          => trim($xpath->evaluate('string(./serie)', $rNode)),
                            'remision'       => trim($xpath->evaluate('string(./remision)', $rNode)),
                            'total'          => trim($xpath->evaluate('string(./total)', $rNode)),
                            'no_referencia'  => trim($xpath->evaluate('string(./no_referencia)', $rNode)),
                            'no_autorizacion'=> trim($xpath->evaluate('string(./no_autorizacion)', $rNode)),
                        ];
                    }
                }
            }

            $result = $pagoService->execute($remisiones, $usuario, $pass);

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string)$result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string)$result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';

                return $this->soapWrap($out, 200);
            }

            // Tu service devuelve: ['remision' => ['doc','cod','mensaje']]
            $doc     = (string)($result['remision']['doc'] ?? '');
            $cod     = (string)($result['remision']['cod'] ?? '');
            $mensaje = (string)($result['remision']['mensaje'] ?? '');

            $out = '<REMISION>'
                . '<DOC>' . htmlspecialchars($doc) . '</DOC>'
                . '<COD>' . htmlspecialchars($cod) . '</COD>'
                . '<MENSAJE>' . htmlspecialchars($mensaje) . '</MENSAJE>'
                . '</REMISION>';

            return $this->soapWrap($out, 200);
        }

        // =========================
        // (3) REVERSION PAGO INDIVIDUAL
        // =========================

        if ($opName === 'reversion') {
            $usuario = trim($xpath->evaluate('string(./Usuario)', $opNode));
            $pass    = trim($xpath->evaluate('string(./Pass)', $opNode));

            // Remisiones
            $remisiones = [];
            $remNodes = $xpath->query('./Remisiones/Remision', $opNode);
            foreach ($remNodes as $rNode) {
                if (!$rNode instanceof \DOMElement) continue;
                $remisiones[] = [
                    'serie'         => trim($xpath->evaluate('string(./serie)', $rNode)),
                    'remision'      => trim($xpath->evaluate('string(./remision)', $rNode)),
                    'total'         => trim($xpath->evaluate('string(./total)', $rNode)),
                    'no_referencia' => trim($xpath->evaluate('string(./no_referencia)', $rNode)),
                    'no_autorizacion'=> trim($xpath->evaluate('string(./no_autorizacion)', $rNode)),
                ];
            }

            $result = $ReversionService->execute($remisiones, $usuario, $pass);

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string) $result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string) $result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';

                return $this->soapWrap($out, 200);
            }

            $doc = (string) ($result['reversion']['doc'] ?? '');
            $cod = (string) ($result['reversion']['cod'] ?? '');
            $mensaje = (string) ($result['reversion']['mensaje'] ?? '');

            $out = '<REVERSION>'
                . '<DOC>' . htmlspecialchars($doc) . '</DOC>'
                . '<COD>' . htmlspecialchars($cod) . '</COD>'
                . '<MENSAJE>' . htmlspecialchars($mensaje) . '</MENSAJE>'
                . '</REVERSION>';

            return $this->soapWrap($out, 200);
        }

        // =========================
        // (4) TOTAL
        // =========================
        if ($opName === 'total') {
            $tipoPlaca = trim($xpath->evaluate('string(./Tipo_Placa)', $opNode));
            $placa     = trim($xpath->evaluate('string(./Placa)', $opNode));
            $usuario   = trim($xpath->evaluate('string(./Usuario)', $opNode));
            $clave     = trim($xpath->evaluate('string(./Clave)', $opNode));

            $result = $TotalConsultaService->execute($tipoPlaca, $placa, $usuario, $clave);

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string) $result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string) $result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';

                return $this->soapWrap($out, 200);
            }

            $doc = (string) ($result['total']['doc'] ?? '');
            $totalVenta = (string) ($result['total']['total_venta'] ?? '');
            $cod = (string) ($result['total']['cod'] ?? '');
            $mensaje = (string) ($result['total']['mensaje'] ?? '');

            $out = '<TOTAL>'
                . '<DOC>' . htmlspecialchars($doc) . '</DOC>'
                . '<TOTAL_VENTA>' . htmlspecialchars($totalVenta) . '</TOTAL_VENTA>'
                . '<COD>' . htmlspecialchars($cod) . '</COD>'
                . '<MENSAJE>' . htmlspecialchars($mensaje) . '</MENSAJE>'
                . '</TOTAL>';

            return $this->soapWrap($out, 200);
        }

        // =========================
        // (5) TOTAL PAGO
        // =========================
        if ($opName === 'totalpago') {
            $usuario = trim($xpath->evaluate('string(./Usuario)', $opNode));
            $pass    = trim($xpath->evaluate('string(./Pass)', $opNode));
            $remisiones = [];
            $remNodes = $xpath->query('./Remisiones/Remision', $opNode);
            foreach ($remNodes as $rNode) {
                if (!$rNode instanceof \DOMElement) continue;
                $remisiones[] = [
                    'serie'         => trim($xpath->evaluate('string(./serie)', $rNode)),
                    'remision'      => trim($xpath->evaluate('string(./remision)', $rNode)),
                    'total'         => trim($xpath->evaluate('string(./total)', $rNode)),
                    'no_referencia' => trim($xpath->evaluate('string(./no_referencia)', $rNode)),
                    'no_autorizacion'=> trim($xpath->evaluate('string(./no_autorizacion)', $rNode)),
                ];
            }
            
            $result = $TotalPagoService->execute($remisiones, $usuario, $pass);

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string) $result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string) $result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';

                return $this->soapWrap($out, 200);
            }

            $doc = (string) ($result['reversion']['doc'] ?? '');
            $cod = (string) ($result['reversion']['cod'] ?? '');
            $mensaje = (string) ($result['reversion']['mensaje'] ?? '');

            $out = '<REVERSION>'
                . '<DOC>' . htmlspecialchars($doc) . '</DOC>'
                . '<COD>' . htmlspecialchars($cod) . '</COD>'
                . '<MENSAJE>' . htmlspecialchars($mensaje) . '</MENSAJE>'
                . '</REVERSION>';

            return $this->soapWrap($out, 200);
            
        }

        // Operación no soportada
        return $this->soapWrap(
            '<ERROR><COD>999</COD><MENSAJE>OPERACION NO SOPORTADA</MENSAJE></ERROR>',
            400
        );
    }

    private function soapWrap(string $innerXml, int $status): Response
    {
        $responseXml =
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">' .
                '<soapenv:Body>' . $innerXml . '</soapenv:Body>' .
            '</soapenv:Envelope>';

        return new Response($responseXml, $status, [
            'Content-Type' => 'text/xml; charset=UTF-8'
        ]);
    }

}