<?php

use Webimpian\Sso\Tests\User;

beforeEach(function () {
    User::create(['name' => 'Aina', 'email' => 'aina@webimpian.com']);
});

it('ends the app session the portal says has ended', function () {
    $this->signIn();

    $this->post('auth/sso/backchannel-logout', ['logout_token' => $this->logoutToken()])->assertOk();

    $this->get('dashboard')->assertRedirect('http://localhost/auth/sso/login');
    $this->assertGuest();
});

it('only ends the session named in the logout token', function () {
    $this->signIn();

    $this->post('auth/sso/backchannel-logout', ['logout_token' => $this->logoutToken(['sid' => 'another-session'])])->assertOk();

    $this->get('dashboard')->assertOk();
});

it('rejects logout tokens it cannot trust', function (array $claims) {
    $this->signIn();

    $this->post('auth/sso/backchannel-logout', ['logout_token' => $this->logoutToken($claims)])->assertStatus(400);

    $this->get('dashboard')->assertOk();
})->with([
    'an ID token' => [['nonce' => 'abc']],
    'no logout event' => [['events' => []]],
    'another app' => [['aud' => 'other-client']],
    'expired' => [['exp' => time() - 120]],
]);

it('ends an idle session and asks for a full sign-in to come back', function () {
    $this->signIn(['idle_timeout' => 1800]);

    $this->travel(29)->minutes();
    $this->get('dashboard')->assertOk();

    $this->travel(31)->minutes();
    $this->get('dashboard')->assertRedirect('http://localhost/auth/sso/login');
    $this->assertGuest();

    expect($this->portalQuery($this->get('auth/sso/login'))['prompt'])->toBe('login');
});

it('tells background requests the session ended instead of redirecting them', function () {
    $this->signIn(['idle_timeout' => 1800]);
    $this->travel(31)->minutes();

    $this->getJson('dashboard')->assertStatus(419);
});

it('checks access with the portal silently once the interval passes', function () {
    $this->signIn(['access_check_interval' => 600]);

    $this->get('dashboard')->assertOk();

    $this->travel(11)->minutes();

    expect($this->portalQuery($this->get('dashboard'))['prompt'])->toBe('none');
});

it('never checks access on a form post or a partial reload', function () {
    $this->signIn(['access_check_interval' => 600]);
    $this->travel(11)->minutes();

    $this->post('notes')->assertOk();
    $this->get('dashboard', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Dashboard'])->assertOk();
});

it('sends Inertia visits to the portal with a full page visit', function () {
    $this->signIn(['access_check_interval' => 600]);
    $this->travel(11)->minutes();

    $this->get('dashboard', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('keeps the session when the portal is down', function () {
    $this->signIn(['access_check_interval' => 600]);
    $this->travel(11)->minutes();

    $this->portalDown = true;

    $this->get('dashboard')->assertOk();
});

it('handles each answer to the silent check', function (string $error, int|string $expected, bool $signedIn) {
    $this->signIn(['access_check_interval' => 600]);
    $this->travel(11)->minutes();
    $state = $this->portalQuery($this->get('dashboard'))['state'];

    $response = $this->get('auth/sso/callback?'.http_build_query(['error' => $error, 'state' => $state]));

    is_int($expected) ? $response->assertStatus($expected) : $response->assertRedirectContains($expected);
    expect(auth()->check())->toBe($signedIn);
})->with([
    'access removed' => ['access_denied', 403, false],
    'portal session gone' => ['login_required', 'https://portal.test/oauth/authorize', false],
    'WorkOS down' => ['temporarily_unavailable', 'http://localhost/dashboard', true],
]);

it('renews the check after a silent sign-in succeeds', function () {
    $this->signIn(['access_check_interval' => 600]);
    $this->travel(11)->minutes();
    $this->get('dashboard');

    $this->signIn(['access_check_interval' => 600])->assertRedirect('http://localhost/dashboard');

    $this->get('dashboard')->assertOk();
});
