<?php

use Illuminate\Support\Facades\Auth;
use Webimpian\Sso\Sso;
use Webimpian\Sso\SsoException;
use Webimpian\Sso\Tests\User;

beforeEach(function () {
    Sso::syncRolesUsing(fn (User $user, array $roles) => $user->forceFill(['role' => implode(',', $roles)])->save());
});

it('asks people without an account to get one by default', function () {
    $this->signIn()->assertForbidden()->assertSee('Ask an admin of this app to add you');

    expect(User::count())->toBe(0);
});

it('creates the account with the default role in auto mode', function () {
    config(['sso.provisioning' => 'auto', 'sso.default_role' => 'viewer']);

    $this->signIn()->assertRedirect('/');

    expect(User::sole())
        ->email->toBe('aina@webimpian.com')
        ->sso_sub->toBe('staff-uuid-1')
        ->role->toBe('viewer')
        ->and(Auth::check())->toBeTrue();
});

it('holds new accounts until approved in pending-approval mode', function () {
    config(['sso.provisioning' => 'pending-approval']);

    $this->signIn()->assertForbidden()->assertSee('needs to approve');
    $this->assertGuest();

    $this->artisan('sso:approve', ['email' => 'AINA@webimpian.com'])->assertSuccessful();

    $this->signIn()->assertRedirect('/');
    $this->assertAuthenticated();
});

it('maps portal roles to app roles on every sign-in', function () {
    config(['sso.role_map' => 'admin:manager, member:viewer', 'sso.default_role' => 'guest']);
    $user = User::create(['name' => 'Aina', 'email' => 'aina@webimpian.com', 'role' => 'owner']);

    $this->signIn(['roles' => ['member', 'admin', 'unmapped']]);
    expect($user->fresh()->role)->toBe('viewer,manager');

    $this->signIn(['roles' => ['unmapped']]);
    expect($user->fresh()->role)->toBe('guest');
});

it('leaves roles alone without a role map', function () {
    config(['sso.default_role' => 'viewer']);
    $user = User::create(['name' => 'Aina', 'email' => 'aina@webimpian.com', 'role' => 'owner']);

    $this->signIn(['roles' => ['member']]);

    expect($user->fresh()->role)->toBe('owner');
});

it('lets the app keep its own sign-in rules', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@webimpian.com']);

    Sso::resolveUsersUsing(function (array $claims) {
        throw SsoException::refuse('Your access to this app was removed.');
    });

    $this->signIn()->assertForbidden()->assertSee('Your access to this app was removed.');
    $this->assertGuest();
});

it('copies the portal name and email onto the account at every sign-in', function () {
    $user = User::create(['name' => 'Old Name', 'email' => 'old@webimpian.com', 'sso_sub' => 'staff-uuid-1']);

    $this->signIn(['name' => 'Aina Rahman', 'email' => 'aina@webimpian.com'])->assertRedirect('/');

    expect($user->fresh())->name->toBe('Aina Rahman')->email->toBe('aina@webimpian.com');
});

it('keeps the old email when another account has the new one, or when turned off', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'old@webimpian.com', 'sso_sub' => 'staff-uuid-1']);
    User::create(['name' => 'Someone Else', 'email' => 'aina@webimpian.com']);

    $this->signIn(['name' => 'Aina Rahman'])->assertRedirect('/');
    expect($user->fresh())->name->toBe('Aina Rahman')->email->toBe('old@webimpian.com');

    config(['sso.sync_profile' => false]);
    $this->post('auth/sso/logout');
    $this->signIn(['name' => 'Renamed'])->assertRedirect('/');
    expect($user->fresh()->name)->toBe('Aina Rahman');
});
