<?php

namespace App\Utils;

class Validator
{
    public function validPlaca(string $placa): bool
    {
        $placa = strtoupper(trim($placa));
        $placa = str_replace([' ', '-'], '', $placa);

        $placa = str_pad($placa, 6, '0', STR_PAD_LEFT);

        if (strlen($placa) !== 6) {
            return false;
        }

        $literales = substr($placa, -3);
        $numeros   = substr($placa, 0, 3);

        return ctype_alpha($literales) && ctype_digit($numeros);
    }
    
    public function validUser(string $usuario, string $password): bool
    {
        return $usuario === 'demo' && $password === 'demo123';
    }
}