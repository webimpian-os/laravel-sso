<?php

namespace Webimpian\Sso\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Webimpian\Sso\Portal;
use Webimpian\Sso\SignOut;
use Webimpian\Sso\Sso;

class SignOutController
{
    public function __invoke(Request $request): Response
    {
        Sso::endSession($request);

        return SignOut::response($request) ?? redirect()->to(Portal::postLogoutUri());
    }
}
