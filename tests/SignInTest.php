<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Webimpian\Sso\Portal;
use Webimpian\Sso\Tests\User;

it('sends staff to the portal with PKCE, state and nonce', function () {
    $query = $this->portalQuery($this->get('auth/sso/login')->assertRedirectContains('https://portal.test/oauth/authorize?'));

    expect($query)->toMatchArray([
        'client_id' => 'app-client',
        'redirect_uri' => 'http://localhost/auth/sso/callback',
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'code_challenge_method' => 'S256',
    ])->and($query['state'])->toHaveLength(40)
        ->and($query['nonce'])->toHaveLength(40)
        ->and($query['code_challenge'])->toHaveLength(43)
        ->and($query)->not->toHaveKey('prompt');
});

it('swaps the code with the verifier and signs the person in', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'Aina@example.com']);

    $this->signIn()->assertRedirect('/');

    expect(Auth::id())->toBe($user->id)
        ->and($user->fresh()->sso_sub)->toBe('staff-uuid-1');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://portal.test/oauth/token'
        && $request['code'] === 'code-1'
        && $request['client_secret'] === 'app-secret'
        && strlen($request['code_verifier']) === 64);
});

it('finds people by sub after the first sign-in, whatever their email', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'old@example.com', 'sso_sub' => 'staff-uuid-1']);

    $this->signIn(['email' => 'aina@example.com']);

    expect(Auth::id())->toBe($user->id);
});

it('links by WorkOS id before email for apps moving off their own WorkOS login', function () {
    config(['sso.workos_id_column' => 'workos_id']);
    User::create(['name' => 'Same email', 'email' => 'aina@example.com']);
    $user = User::create(['name' => 'Aina', 'email' => 'aina.old@example.com', 'workos_id' => 'user_01ABC']);

    $query = $this->portalQuery($this->get('auth/sso/login'));
    expect($query['scope'])->toBe('openid profile email staff');

    $this->signIn(['workos_user_id' => 'user_01ABC']);

    expect(Auth::id())->toBe($user->id)
        ->and($user->fresh()->sso_sub)->toBe('staff-uuid-1');
});

it('never links an email that already belongs to another person', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'someone-else']);

    $this->signIn()->assertForbidden()->assertSee('belongs to another account');

    $this->assertGuest();
});

it('rejects a token that is not for this sign-in', function (array $claims, ?string $signedWith) {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com']);

    $this->signIn($claims, sign: fn (array $payload) => $this->sign($payload, 'key-1', $signedWith))->assertStatus(502);

    $this->assertGuest();
})->with([
    'another nonce' => [['nonce' => 'not-the-one-sent'], null],
    'another issuer' => [['iss' => 'https://evil.test'], null],
    'another app' => [['aud' => 'other-client'], null],
    'expired' => [['exp' => time() - 120], null],
    'no expiry' => [['exp' => null], null],
    'the published kid on another key' => [[], 'forged'],
]);

it('refuses a callback whose state it never sent', function () {
    $this->get('auth/sso/callback?code=code-1&state=made-up')->assertStatus(400)->assertSee('expired');

    Http::assertNothingSent();
});

it('picks up a rotated signing key without a restart', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com']);
    $this->signIn();
    Auth::logout();

    $this->portalKeys = ['key-1', 'key-2'];
    $this->signIn([], 'key-2')->assertRedirect('/');

    $this->assertAuthenticated();
});

it('opens through the sign-in URL and returns to the page asked for', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com']);

    $this->get('auth/sso/login?'.http_build_query(['iss' => 'https://portal.test', 'target_link_uri' => 'http://localhost/dashboard']));

    $this->signIn()->assertRedirect('http://localhost/dashboard');
});

it('ignores a target on another site', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com']);

    $this->get('auth/sso/login?'.http_build_query(['iss' => 'https://portal.test', 'target_link_uri' => 'https://evil.test/']));

    $this->signIn()->assertRedirect('/');
});

it('refuses a sign-in URL from another issuer', function () {
    $this->get('auth/sso/login?iss=https://evil.test')->assertStatus(400);
});

it('asks for SSO_ISSUER when it is missing', function () {
    config(['sso.issuer' => null]);

    expect(fn () => Portal::issuer())->toThrow(LogicException::class, 'Set SSO_ISSUER to the SSO portal URL.');
});

it('does not link an unverified email when staff sync is on', function () {
    config(['sso.staff.enabled' => true]);
    $user = User::create(['name' => 'Aina', 'email' => 'aina@example.com']);

    $this->signIn()->assertForbidden();

    $this->assertGuest();
    expect($user->fresh()->sso_sub)->toBeNull();
});

it('links an unverified email when staff sync is off', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'aina@example.com']);

    $this->signIn()->assertRedirect('/');

    expect($user->fresh()->sso_sub)->toBe('staff-uuid-1');
});

it('links a verified email when staff sync is on', function () {
    config(['sso.staff.enabled' => true]);
    $user = User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now()]);

    $this->signIn()->assertRedirect('/');

    expect($user->fresh()->sso_sub)->toBe('staff-uuid-1');
});
