<?php

namespace Webimpian\Sso\Testing;

use Webimpian\Sso\Portal;

class FakePortal extends Portal
{
    public function __construct(private array $claims = []) {}

    // Straight back to the callback, as the portal does for someone already signed in.
    public function authorizeUrl(array $query): string
    {
        return $query['redirect_uri'].'?'.http_build_query(['code' => 'fake', 'state' => $query['state']]);
    }

    public function signIn(string $code, string $verifier, string $nonce): array
    {
        return ['id_token' => 'fake-id-token', 'claims' => [
            'sub' => 'fake-staff',
            'sid' => 'fake-session',
            'email' => 'staff@example.com',
            'name' => 'Fake Staff',
            ...$this->claims,
            'nonce' => $nonce,
        ]];
    }

    // A fake logout token is the session id it ends.
    public function logoutClaims(string $logoutToken): array
    {
        return ['sid' => $logoutToken];
    }

    public function endSessionUrl(): string
    {
        return static::issuer().'/oauth/logout';
    }

    public function reachable(): bool
    {
        return true;
    }
}
