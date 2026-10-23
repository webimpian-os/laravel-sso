<?php

namespace Webimpian\Sso\Filament;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as Contract;
use Filament\Auth\Http\Responses\LogoutResponse as FilamentLogoutResponse;
use Webimpian\Sso\SignOut;

// Filament 4 and 5.
class LogoutResponse implements Contract
{
    public function toResponse($request)
    {
        return SignOut::response($request) ?? (new FilamentLogoutResponse)->toResponse($request);
    }
}
