<?php

use Illuminate\Support\Facades\Route;
use Webimpian\Sso\Http\Controllers\BackchannelLogoutController;
use Webimpian\Sso\Http\Controllers\SignInController;
use Webimpian\Sso\Http\Controllers\SignOutController;

Route::middleware('web')->group(function () {
    Route::get('auth/sso/login', [SignInController::class, 'start'])->name('sso.login');
    Route::get('auth/sso/callback', [SignInController::class, 'callback'])->name('sso.callback');
    Route::post('auth/sso/logout', SignOutController::class)->name('sso.logout');
});

// Called server to server by the portal, so there is no session or CSRF token.
Route::post('auth/sso/backchannel-logout', BackchannelLogoutController::class)->name('sso.backchannel');
