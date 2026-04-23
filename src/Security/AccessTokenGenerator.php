<?php

namespace App\Security;

final class AccessTokenGenerator
{
    public function generate(): string
    {
        return 'atk_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function prefix(string $token, int $length = 16): string
    {
        return substr($token, 0, $length);
    }
}