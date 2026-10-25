<?php

use Webimpian\Sso\Portal;
use Webimpian\Sso\SsoException;

it('reads a valid staff token', function () {
    expect(app(Portal::class)->staffClaims($this->staffToken())['action'])->toBe('levels');
});

it('refuses tokens that are not staff notices', function (Closure $make) {
    expect(fn () => app(Portal::class)->staffClaims(Closure::bind($make, $this, $this)()))->toThrow(SsoException::class);
})->with([
    'logout token' => [fn () => $this->logoutToken()],
    'no typ' => [fn () => $this->sign(['iss' => 'https://portal.test', 'aud' => 'app-client', 'iat' => time(), 'exp' => time() + 60, 'jti' => 'j1', 'action' => 'levels'])],
    'other audience' => [fn () => $this->staffToken(['aud' => 'other-client'])],
    'expired' => [fn () => $this->staffToken(['exp' => time() - 120])],
    'lives too long' => [fn () => $this->staffToken(['iat' => time(), 'exp' => time() + 300])],
    'unknown action' => [fn () => $this->staffToken(['action' => 'promote'])],
]);

it('refuses a replayed staff token', function () {
    $token = $this->staffToken();
    app(Portal::class)->staffClaims($token);

    expect(fn () => app(Portal::class)->staffClaims($token))->toThrow(SsoException::class);
});
