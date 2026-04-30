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
use App\Security\JwtClientUser;
use App\Utils\Validator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use App\Utils\JwtHelper;
use App\Application\Security\BearerTokenAuthenticatorService;
use Psr\Log\LoggerInterface;
use App\Application\Security\RequestSecurityHeadersValidator;
use App\Application\Security\ReplayGuardService;

class SoapController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    #[Route('/api/soap', methods: ['POST'])]
    public function handle(
        Request $request, 
        ConsultaIndividualService $consultaService,
        PagoIndividualService $pagoService,
        ReversionIndividualService $ReversionService,
        TotalConsultaService $TotalConsultaService,
        TotalPagoService $TotalPagoService,
        Validator $validator,
        JWTTokenManagerInterface $jwtManager,
        JwtHelper $jwtHelper,
        BearerTokenAuthenticatorService $bearerAuthenticator,        
        RequestSecurityHeadersValidator $headersValidator,
        ReplayGuardService $replayGuardService,
    ): Response{
        $raw = $request->getContent() ?? '';
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); 
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

        $xpath->registerNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
        
        $xpath->registerNamespace('soap12', 'http://www.w3.org/2003/05/soap-envelope');

        $opNode = $xpath->query('//soapenv:Body/*[1]')->item(0)
            ?? $xpath->query('//soap12:Body/*[1]')->item(0);

        if (!$opNode instanceof \DOMElement) {
            return $this->soapWrap(
                '<ERROR><COD>999</COD><MENSAJE>SOAP BODY NO ENCONTRADO</MENSAJE></ERROR>',
                400
            );
        }

        $opName = strtolower($opNode->localName ?? $opNode->nodeName);
        $ip         = (string)($request->getClientIp() ?? '');
    

        // =========================
        // Validar token y acceso
        // =========================
        try {
            $authenticatedClient = $bearerAuthenticator->authenticate($request);
        } catch (\RuntimeException $e) {
            return $this->soapWrap(
                '<ERROR><COD>401</COD><MENSAJE>TOKEN INVALIDO O AUSENTE</MENSAJE></ERROR>',
                401
            );
        }

        //Validar headers
        try {
            $securityHeaders = $headersValidator->validateHeaders(
                $request,
                'application/soap+xml',
                300
            );
        } catch (\RuntimeException $e) {
            return $this->soapWrap(
                '<ERROR><COD>400</COD><MENSAJE>HEADERS DE SEGURIDAD INVALIDOS: '
                . htmlspecialchars($e->getMessage())
                . '</MENSAJE></ERROR>',
                400
            );
        }

        
        $requestId = $securityHeaders['request_id'];
        $requestTimestamp = $securityHeaders['timestamp'];

        try {
            $replayGuardService->validateAndRegister(
                $authenticatedClient->bankClientId,
                $requestId,
                900
            );
        } catch (\RuntimeException $e) {
            return $this->soapWrap(
                '<ERROR><COD>409</COD><MENSAJE>REQUEST_ID REPETIDO</MENSAJE></ERROR>',
                409
            );
        }

        $subject = (string) $authenticatedClient->bankClientId;

        $this->logger->info('Solicitud SOAP recibida', [
            'operacion' => $opName,
            'subject' => $subject,
            'ip' => $ip,
            'requestId' => $requestId,
            'timeStamp' => $requestTimestamp
        ]);

        // =========================
        // (1) Consulta
        // =========================
        if ($opName === 'consulta') {
            $tipoPlaca = $this->x($xpath, $opNode, 'Tipo_Placa');
            $placa     = $this->x($xpath, $opNode, 'Placa');

            $result = $consultaService->execute($tipoPlaca, $placa, $subject, $ip);

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

            $remisiones = [];

            // Caso 1: <Remisiones><Remision>...</Remision></Remisiones>
            $remNodes = $this->q($xpath, $opNode, 'Remisiones/Remision');
            if ($remNodes && $remNodes->length > 0) {
                foreach ($remNodes as $rNode) {
                    if (!$rNode instanceof \DOMElement) {
                        continue;
                    }

                    $remisiones[] = [
                        'serie'           => $this->x($xpath, $rNode, 'serie'),
                        'remision'        => $this->x($xpath, $rNode, 'remision'),
                        'total'           => $this->x($xpath, $rNode, 'total'),
                        'no_referencia'   => $this->x($xpath, $rNode, 'no_referencia'),
                        'no_autorizacion' => $this->x($xpath, $rNode, 'no_autorizacion'),
                    ];
                }
            } else {
                // Caso 2 fallback: <Remision> directo dentro de <pago>
                $remNodes2 = $this->q($xpath, $opNode, 'Remision');
                if ($remNodes2 && $remNodes2->length > 0) {
                    foreach ($remNodes2 as $rNode) {
                        if (!$rNode instanceof \DOMElement) {
                            continue;
                        }

                        $remisiones[] = [
                            'serie'           => $this->x($xpath, $rNode, 'serie'),
                            'remision'        => $this->x($xpath, $rNode, 'remision'),
                            'total'           => $this->x($xpath, $rNode, 'total'),
                            'no_referencia'   => $this->x($xpath, $rNode, 'no_referencia'),
                            'no_autorizacion' => $this->x($xpath, $rNode, 'no_autorizacion'),
                        ];
                    }
                }
            } 

            $result = $pagoService->execute($remisiones, $subject, $ip);

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string)$result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string)$result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';

                return $this->soapWrap($out, 200);
            }

            $doc     = (string)($result['remision']['doc'] ?? '');
            $cod     = (string)($result['remision']['cod'] ?? '');
            $mensaje = (string)($result['remision']['mensaje'] ?? '');

            $out = '<REMISION>'
                . '<DOC>' . htmlspecialchars($doc) . '</DOC>'
                . '<COD>' . htmlspecialchars($cod) . '</COD>'
                . '<MENSAJE>' . htmlspecialchars($mensaje) . '</MENSAJE>';

            // Caso parcial: agregar detalle de procesadas y no procesadas
            if (!empty($result['procesadas']) || !empty($result['no_procesadas'])) {
                $out .= '<DETALLE>';

                if (!empty($result['procesadas'])) {
                    $out .= '<PROCESADAS>';
                    foreach ($result['procesadas'] as $p) {
                        $out .= '<ITEM>'
                            . '<SERIE>' . htmlspecialchars((string)($p['serie'] ?? '')) . '</SERIE>'
                            . '<REMISION>' . htmlspecialchars((string)($p['remision'] ?? '')) . '</REMISION>'
                            . '<CODIGO>' . htmlspecialchars((string)($p['codigo'] ?? '')) . '</CODIGO>'
                            . '<MENSAJE>' . htmlspecialchars((string)($p['mensaje'] ?? '')) . '</MENSAJE>'
                            . '</ITEM>';
                    }
                    $out .= '</PROCESADAS>';
                }

                if (!empty($result['no_procesadas'])) {
                    $out .= '<NO_PROCESADAS>';
                    foreach ($result['no_procesadas'] as $np) {
                        $out .= '<ITEM>'
                            . '<SERIE>' . htmlspecialchars((string)($np['serie'] ?? '')) . '</SERIE>'
                            . '<REMISION>' . htmlspecialchars((string)($np['remision'] ?? '')) . '</REMISION>'
                            . '<CODIGO>' . htmlspecialchars((string)($np['codigo'] ?? '')) . '</CODIGO>'
                            . '<MENSAJE>' . htmlspecialchars((string)($np['mensaje'] ?? '')) . '</MENSAJE>'
                            . '</ITEM>';
                    }
                    $out .= '</NO_PROCESADAS>';
                }

                $out .= '</DETALLE>';
            }

            $out .= '</REMISION>';

            return $this->soapWrap($out, 200);
        }

        // =========================
        // (3) REVERSION PAGO INDIVIDUAL
        // =========================

        if ($opName === 'reversion') {
            $documento = $this->x($xpath, $opNode, 'Documento');
            $message   = $this->x($xpath, $opNode, 'Message');

            $result = $ReversionService->execute($documento, $subject, $message, $ip);

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string)$result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string)$result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';

                return $this->soapWrap($out, 200);
            }

            $doc = (string)($result['reversion']['doc'] ?? '');
            $cod = (string)($result['reversion']['cod'] ?? '');
            $mensaje = (string)($result['reversion']['mensaje'] ?? '');

            $out = '<REMISION>'
                . '<DOC>' . htmlspecialchars($doc) . '</DOC>'
                . '<COD>' . htmlspecialchars($cod) . '</COD>'
                . '<MENSAJE>' . htmlspecialchars($mensaje) . '</MENSAJE>'
                . '</REMISION>';

            return $this->soapWrap($out, 200);
        }

        // =========================
        // (4) TOTAL consuta
        // =========================
        if ($opName === 'total') {
            $tipoPlaca = $this->x($xpath, $opNode, 'Tipo_Placa');
            $placa     = $this->x($xpath, $opNode, 'Placa');

            $result = $TotalConsultaService->execute($tipoPlaca, $placa, $subject, $ip);

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string) $result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string) $result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';

                return $this->soapWrap($out, 200);
            }

            $fecha = (string) ($result['total']['fecha'] ?? '');
            $total = (string) ($result['total']['total'] ?? '');

            $out = '<RESULTADO>'
                . '<FECHA>' . htmlspecialchars($fecha) . '</FECHA>'
                . '<TOTAL>' . htmlspecialchars($total) . '</TOTAL>'
                . '</RESULTADO>';

            return $this->soapWrap($out, 200);
        }

        // =========================
        // (5) TOTAL PAGO
        // =========================
        if ($opName === 'totalpago') {
            $tipoPlaca      = $this->x($xpath, $opNode, 'Tipo_Placa');
            $placa          = $this->x($xpath, $opNode, 'Placa');
            $total          = $this->x($xpath, $opNode, 'Total');
            $noReferencia   = $this->x($xpath, $opNode, 'No_Referencia');
            $noAutorizacion = $this->x($xpath, $opNode, 'No_Autorizacion');

            $result = $TotalPagoService->execute(
                $tipoPlaca,
                $placa,
                $total,
                $noReferencia,
                $noAutorizacion,
                $subject,
                // $pass,
                $ip
            );

            if (isset($result['error'])) {
                $out = '<ERROR>'
                    . '<COD>' . htmlspecialchars((string)$result['error']['cod']) . '</COD>'
                    . '<MENSAJE>' . htmlspecialchars((string)$result['error']['mensaje']) . '</MENSAJE>'
                    . '</ERROR>';

                return $this->soapWrap($out, 200);
            }

            $doc     = (string)($result['total_pago']['doc'] ?? '');
            $cod     = (string)($result['total_pago']['cod'] ?? '');
            $mensaje = (string)($result['total_pago']['mensaje'] ?? '');

            $out = '<REMISION>'
                . '<DOC>' . htmlspecialchars($doc) . '</DOC>'
                . '<COD>' . htmlspecialchars($cod) . '</COD>'
                . '<MENSAJE>' . htmlspecialchars($mensaje) . '</MENSAJE>';

            if (!empty($result['procesadas']) || !empty($result['no_procesadas'])) {
                $out .= '<DETALLE>';

                if (!empty($result['procesadas'])) {
                    $out .= '<PROCESADAS>';
                    foreach ($result['procesadas'] as $p) {
                        $out .= '<ITEM>'
                            . '<SERIE>' . htmlspecialchars((string)($p['serie'] ?? '')) . '</SERIE>'
                            . '<REMISION>' . htmlspecialchars((string)($p['remision'] ?? '')) . '</REMISION>'
                            . '<DOCUMENTO>' . htmlspecialchars((string)($p['documento'] ?? '')) . '</DOCUMENTO>'
                            . '<COD>' . htmlspecialchars((string)($p['codigo'] ?? '')) . '</COD>'
                            . '<MENSAJE>' . htmlspecialchars((string)($p['mensaje'] ?? '')) . '</MENSAJE>'
                            . '</ITEM>';
                    }
                    $out .= '</PROCESADAS>';
                }

                if (!empty($result['no_procesadas'])) {
                    $out .= '<NO_PROCESADAS>';
                    foreach ($result['no_procesadas'] as $np) {
                        $out .= '<ITEM>'
                            . '<SERIE>' . htmlspecialchars((string)($np['serie'] ?? '')) . '</SERIE>'
                            . '<REMISION>' . htmlspecialchars((string)($np['remision'] ?? '')) . '</REMISION>'
                            . '<COD>' . htmlspecialchars((string)($np['codigo'] ?? '')) . '</COD>'
                            . '<MENSAJE>' . htmlspecialchars((string)($np['mensaje'] ?? '')) . '</MENSAJE>'
                            . '</ITEM>';
                    }
                    $out .= '</NO_PROCESADAS>';
                }

                $out .= '</DETALLE>';
            }

            $out .= '</REMISION>';

            return $this->soapWrap($out, 200);
        }

        // Operación no soportada
        return $this->soapWrap(
            '<ERROR><COD>999</COD><MENSAJE>OPERACION NO SOPORTADA</MENSAJE></ERROR>',
            400
        );
    }

    private function x(\DOMXPath $xpath, \DOMNode $context, string $name): string
    {
        return trim($xpath->evaluate('string(./*[local-name()="' . $name . '"])', $context));
    }

    private function q(\DOMXPath $xpath, \DOMNode $context, string $path): \DOMNodeList|false
    {
        $parts = array_filter(explode('/', $path));
        $expr = '.';

        foreach ($parts as $part) {
            $expr .= '/*[local-name()="' . $part . '"]';
        }

        return $xpath->query($expr, $context);
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

    #[Route('/api/soap/wsdl', methods: ['GET'])]
    public function wsdl(): Response
    {
        $wsdlPath = $this->getParameter('kernel.project_dir') . '/public/wsdl/servicio_bancos.wsdl';

        if (!file_exists($wsdlPath)) {
            return new Response('WSDL no encontrado', 404);
        }

        return new Response(file_get_contents($wsdlPath), 200, [
            'Content-Type' => 'text/xml; charset=UTF-8',
        ]);
    }

}
