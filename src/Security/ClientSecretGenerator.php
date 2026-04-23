<?php

namespace App\Security;

final class ClientSecretGenerator
{
    public function generate(): string
    {
        return 'cs_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}