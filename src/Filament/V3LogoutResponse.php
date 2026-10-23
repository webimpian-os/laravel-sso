<?php

namespace Webimpian\Sso\Filament;

use Filament\Http\Responses\Auth\Contracts\LogoutResponse as Contract;
use Filament\Http\Responses\Auth\LogoutResponse as FilamentLogoutResponse;
use Webimpian\Sso\SignOut;

class V3LogoutResponse implements Contract
{
    public function toResponse($request)
    {
        return SignOut::response($request) ?? (new FilamentLogoutResponse)->toResponse($request);
    }
}
