<?php

namespace App\Application\Security;

use Symfony\Component\HttpFoundation\Request;

final class RequestSecurityHeadersValidator
{
    public function validateSoapHeaders(Request $request, int $clockSkewSeconds = 300): array
    {
        $this->validateContentType($request);
        $requestId = $this->validateRequestId($request);
        $timestamp = $this->validateTimestamp($request, $clockSkewSeconds);

        return [
            'request_id' => $requestId,
            'timestamp' => $timestamp,
        ];
    }

    private function validateContentType(Request $request): void
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));

        if (!str_contains($contentType, 'application/soap+xml')) {
            throw new \RuntimeException('invalid_content_type');
        }
    }

    private function validateRequestId(Request $request): string
    {
        $requestId = trim((string) $request->headers->get('X-Request-Id', ''));

        if ($requestId === '') {
            throw new \RuntimeException('missing_request_id');
        }

        $uuidRegex = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-7][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        if (!preg_match($uuidRegex, $requestId)) {
            throw new \RuntimeException('invalid_request_id');
        }

        return $requestId;
    }

    private function validateTimestamp(Request $request, int $clockSkewSeconds): string
    {
        $timestamp = trim((string) $request->headers->get('X-Timestamp', ''));

        if ($timestamp === '') {
            throw new \RuntimeException('missing_timestamp');
        }

        try {
            $requestTime = new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            throw new \RuntimeException('invalid_timestamp');
        }

        if ($requestTime->getOffset() !== 0) {
            throw new \RuntimeException('timestamp_must_be_utc');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $diff = abs($now->getTimestamp() - $requestTime->getTimestamp());

        if ($diff > $clockSkewSeconds) {
            throw new \RuntimeException('timestamp_out_of_range');
        }

        return $requestTime->format('Y-m-d\TH:i:s\Z');
    }
}