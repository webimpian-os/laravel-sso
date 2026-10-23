<?php

namespace Webimpian\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SignIn
{
    // prompt "none" is the silent access check; "login" asks for a full sign-in.
    public static function url(Request $request, ?string $prompt = null): string
    {
        $state = Str::random(40);
        $flow = ['nonce' => Str::random(40), 'verifier' => Str::random(64), 'silent' => $prompt === 'none'];

        // Keyed by state so a second tab doesn't break the first.
        $request->session()->put('sso_flows', array_slice([...$request->session()->get('sso_flows', []), $state => $flow], -5));

        return app(Portal::class)->authorizeUrl(array_filter([
            'client_id' => config('sso.client_id'),
            'redirect_uri' => Portal::redirectUri(),
            'response_type' => 'code',
            'scope' => Portal::scopes(),
            'state' => $state,
            'nonce' => $flow['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => $prompt,
        ]));
    }
}
