<?php

namespace Webimpian\Sso\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Webimpian\Sso\Portal;
use Webimpian\Sso\Sso;
use Webimpian\Sso\SsoException;

class BackchannelLogoutController
{
    public function __invoke(Request $request, Portal $portal): Response
    {
        $token = $request->input('logout_token');

        try {
            $claims = $portal->logoutClaims(is_string($token) ? $token : '');
        } catch (SsoException) {
            return response()->json(['error' => 'invalid_request'], 400, ['Cache-Control' => 'no-store']);
        }

        // Read on that session's next request; kept long enough to outlast any app session.
        Cache::put(Sso::endedKey($claims['sid']), true, now()->addDays(30));

        return response('', 200, ['Cache-Control' => 'no-store']);
    }
}
