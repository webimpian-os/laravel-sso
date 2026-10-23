<?php

namespace Webimpian\Sso;

use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SignOut
{
    // On Laravel's Logout event, before the app's own sign-out empties the session.
    public static function remember(Logout $event): void
    {
        $request = request();
        $idToken = $request->hasSession() ? $request->session()->get('sso.id_token') : null;

        if (is_string($idToken)) {
            $request->attributes->set('sso_id_token', $idToken);
        }
    }

    // Null when the session wasn't signed in through the portal.
    public static function response(Request $request): ?Response
    {
        $idToken = $request->attributes->get('sso_id_token');

        if (! is_string($idToken)) {
            return null;
        }

        try {
            $action = app(Portal::class)->endSessionUrl();
        } catch (SsoException) {
            return redirect()->to(Portal::postLogoutUri());
        }

        // Posted so the ID token stays out of URLs and logs.
        return response()->view('sso::sign-out', ['action' => $action, 'fields' => [
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => Portal::postLogoutUri(),
            'client_id' => config('sso.client_id'),
        ]])->header('Cache-Control', 'no-store');
    }
}
