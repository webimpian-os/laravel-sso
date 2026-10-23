<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Webimpian\Sso\Sso;
use Webimpian\Sso\Tests\User;

it('signs in without calling the portal when faked', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'aina@example.com']);
    Sso::fake(['email' => 'aina@example.com']);

    $this->followingRedirects()->get('dashboard')->assertOk()->assertSee('dashboard');

    expect(Auth::id())->toBe($user->id)->and(Sso::check())->toBeTrue();
    Http::assertNothingSent();
});
