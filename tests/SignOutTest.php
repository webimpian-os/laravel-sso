<?php

use Illuminate\Support\Facades\Auth;
use Webimpian\Sso\Tests\User;

it('signs out of the app and posts the ID token to the portal', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@webimpian.com']);
    $this->signIn();

    $this->post('auth/sso/logout')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('action="https://portal.test/oauth/logout"', false)
        ->assertSee('name="id_token_hint" value="'.$this->nextToken.'"', false)
        ->assertSee('name="post_logout_redirect_uri" value="http://localhost"', false);

    $this->assertGuest();
});

it('sends a local sign-in home without the portal', function () {
    Auth::login(User::create(['name' => 'Break glass', 'email' => 'admin@webimpian.com']));

    $this->post('auth/sso/logout')->assertRedirect('http://localhost');

    $this->assertGuest();
});
