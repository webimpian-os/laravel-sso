<?php

namespace Webimpian\Sso\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Webimpian\Sso\Events\StaffRestored;
use Webimpian\Sso\Events\StaffRevoked;
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
        if (is_string($claims['sid'] ?? null)) {
            Cache::put(Sso::endedKey($claims['sid']), true, now()->addDays(30));
        }

        if (is_bool($claims['revoked'] ?? null) && is_string($claims['sub'] ?? null)) {
            $this->accessChanged($claims['sub'], $claims['revoked']);
        }

        return response('', 200, ['Cache-Control' => 'no-store']);
    }

    private function accessChanged(string $sub, bool $revoked): void
    {
        $revoked
            ? Cache::put(Sso::revokedKey($sub), true, now()->addDays(30))
            : Cache::forget(Sso::revokedKey($sub));

        $user = Sso::userModel()::query()->where('sso_sub', $sub)->first();

        if ($user !== null) {
            event($revoked ? new StaffRevoked($user) : new StaffRestored($user));
        }
    }
}
