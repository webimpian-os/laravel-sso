<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Webimpian\Sso\Sso;
use Webimpian\Sso\SsoException;

it('adds staff through the portal with the app credentials', function () {
    Http::fake(['portal.test/api/staff' => Http::response(['sub' => 'staff-uuid-1', 'email' => 'aina@webimpian.com', 'name' => 'Aina Rahman', 'workos_user_id' => 'user_1', 'created' => true, 'access' => true, 'access_reason' => null], 201)]);

    $staff = Sso::addStaff('aina@webimpian.com', 'Aina', 'Rahman');

    expect($staff)->toMatchArray(['sub' => 'staff-uuid-1', 'created' => true, 'access' => true]);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://portal.test/api/staff'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('app-client:app-secret'))
        && $request->data() === ['email' => 'aina@webimpian.com', 'first_name' => 'Aina', 'last_name' => 'Rahman']);
});

it('passes on why the portal refused', function () {
    Http::fake(['portal.test/api/staff' => Http::response(['error' => 'revoked', 'error_description' => 'aina@webimpian.com had access removed.'], 409)]);

    expect(fn () => Sso::addStaff('aina@webimpian.com', 'Aina'))
        ->toThrow(fn (SsoException $e) => expect([$e->getMessage(), $e->getCode()])->toBe(['aina@webimpian.com had access removed.', 409]));
});

it('adds staff without the portal when faked', function () {
    $fake = Sso::fake();

    expect(Sso::addStaff('Aina@webimpian.com', 'Aina', 'Rahman'))->toMatchArray(['email' => 'aina@webimpian.com', 'name' => 'Aina Rahman', 'created' => true])
        ->and($fake->added)->toBe([['email' => 'Aina@webimpian.com', 'first_name' => 'Aina', 'last_name' => 'Rahman']]);
    Http::assertNothingSent();
});

it('removes staff from the app through the portal', function () {
    Http::fake(['portal.test/api/staff/*' => Http::response(['sub' => 'staff-uuid-1', 'removed' => true, 'access' => false, 'access_reason' => null])]);

    expect(Sso::removeStaff('staff-uuid-1'))->toMatchArray(['removed' => true, 'access' => false]);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://portal.test/api/staff/staff-uuid-1'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('app-client:app-secret')));
});

it('removes staff without the portal when faked', function () {
    $fake = Sso::fake();

    expect(Sso::removeStaff('staff-uuid-1'))->toMatchArray(['removed' => true])
        ->and($fake->removed)->toBe(['staff-uuid-1']);
    Http::assertNothingSent();
});
