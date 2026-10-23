<?php

namespace Webimpian\Sso\Testing;

use Webimpian\Sso\Portal;

class FakePortal extends Portal
{
    // Each addStaff() call, for the app's tests to check.
    public array $added = [];

    public function __construct(private array $claims = []) {}

    // As the portal does for someone already signed in.
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

    public function addStaff(string $email, string $firstName, ?string $lastName = null): array
    {
        $this->added[] = ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName];

        return [
            'sub' => 'fake-'.md5(strtolower($email)),
            'email' => strtolower($email),
            'name' => trim($firstName.' '.$lastName),
            'workos_user_id' => null,
            'created' => true,
            'access' => true,
            'access_reason' => null,
        ];
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
