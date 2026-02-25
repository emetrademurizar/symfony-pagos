<?php

namespace App\Controller\Api\Soap;

use App\Application\Individual\ConsultaIndividualService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SoapController extends AbstractController
{
    #[Route('/api/soap', methods: ['POST'])]
    public function handle(Request $request, ConsultaIndividualService $service): Response
    {
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
        $consulta = $xpath->query('//soapenv:Body/*[1]')->item(0)
            ?? $xpath->query('//soap12:Body/*[1]')->item(0);

        if (!$consulta) {
            return new Response(
                '<ERROR><COD>999</COD><MENSAJE>SOAP BODY NO ENCONTRADO</MENSAJE></ERROR>',
                400,
                ['Content-Type' => 'text/xml; charset=UTF-8']
            );
        }

        $tipoPlaca = trim($xpath->evaluate('string(./Tipo_Placa)', $consulta));
        $placa     = trim($xpath->evaluate('string(./Placa)', $consulta));
        $usuario   = trim($xpath->evaluate('string(./Usuario)', $consulta));
        $pass      = trim($xpath->evaluate('string(./Pass)', $consulta));

        $result = $service->execute($tipoPlaca, $placa, $usuario, $pass);

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

        $responseXml =
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">' .
                '<soapenv:Body>' . $out . '</soapenv:Body>' .
            '</soapenv:Envelope>';

        return new Response($responseXml, 200, [
            'Content-Type' => 'text/xml; charset=UTF-8'
        ]);
    }
}