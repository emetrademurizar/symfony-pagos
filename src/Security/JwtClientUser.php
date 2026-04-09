<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class JwtClientUser implements UserInterface
{
    public function __construct(
        private readonly string $clientId,
    ){}

    public function getuserIdentifier(): string
    {
        return $this->clientId;
    }

    public function getRoles(): array
    {
        return ['ROLE_API_CLIENT'];
    }

    public function eraseCredentials(): void
    {
    }
}