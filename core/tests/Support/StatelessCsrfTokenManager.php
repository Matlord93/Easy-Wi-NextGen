<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class StatelessCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function getToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, $this->valueFor($tokenId));
    }

    public function refreshToken(string $tokenId): CsrfToken
    {
        return $this->getToken($tokenId);
    }

    public function removeToken(string $tokenId): ?string
    {
        return $this->valueFor($tokenId);
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        return hash_equals($this->valueFor($token->getId()), $token->getValue());
    }

    private function valueFor(string $tokenId): string
    {
        return hash('sha256', 'test-csrf:' . $tokenId);
    }
}
