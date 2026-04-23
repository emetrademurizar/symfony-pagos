<?php

namespace App\Security;

final class ClientIdGenerator
{
    public function generate(string $bankCode, string $environment, int $nextNumber): string
    {
        $bankPart = $this->normalizeBankCode($bankCode);
        $envPart = strtolower(trim($environment));
        $seqPart = str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT);

        return sprintf('cid_%s_%s_%s', $bankPart, $envPart, $seqPart);
    }

    private function normalizeBankCode(string $bankCode): string
    {
        $normalized = strtolower(trim($bankCode));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : 'bank';
    }
}