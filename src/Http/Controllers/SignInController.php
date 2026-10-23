<?php

namespace Webimpian\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webimpian\Sso\Accounts;
use Webimpian\Sso\Portal;
use Webimpian\Sso\SignIn;
use Webimpian\Sso\Sso;
use Webimpian\Sso\SsoException;

class SignInController
{
    // Also the app's sign-in URL, which the portal opens with iss and target_link_uri.
    public function start(Request $request): RedirectResponse
    {
        $issuer = $request->query('iss');

        if ($issuer !== null && $issuer !== Portal::issuer()) {
            throw new SsoException('This sign-in link did not come from the portal.', 400);
        }

        $target = $request->query('target_link_uri');

        if (is_string($target) && static::isOwnUrl($request, $target)) {
            $request->session()->put('url.intended', $target);
        }

        return redirect()->away(SignIn::url($request, $request->session()->pull('sso_prompt')));
    }

    public function callback(Request $request, Portal $portal): RedirectResponse
    {
        $state = $request->query('state');
        $flow = is_string($state) && $state !== '' ? $request->session()->pull('sso_flows.'.$state) : null;

        if (! is_array($flow)) {
            throw new SsoException('This sign-in has expired. Start it again.', 400);
        }

        if ($request->query('error') !== null) {
            return $this->refused($request, (string) $request->query('error'), $flow['silent']);
        }

        try {
            ['id_token' => $idToken, 'claims' => $claims] = $portal->signIn((string) $request->query('code'), $flow['verifier'], $flow['nonce']);
        } catch (SsoException $e) {
            // A failed access check keeps the session until the next one.
            if ($flow['silent'] && Sso::guard()->check()) {
                return redirect()->intended(config('sso.home'));
            }

            throw $e;
        }

        try {
            $user = Sso::$resolveUser ? (Sso::$resolveUser)($claims) : Accounts::resolve($claims);
        } catch (SsoException $e) {
            if ($flow['silent']) {
                Sso::endSession($request);
            }

            throw $e;
        }

        Accounts::syncRoles($user, $claims);

        $guard = Sso::guard();

        if (! $guard->check() || (string) $guard->id() !== (string) $user->getAuthIdentifier()) {
            $guard->login($user);
        }

        $now = now()->getTimestamp();

        $request->session()->put('sso', [
            'sid' => is_string($claims['sid'] ?? null) ? $claims['sid'] : null,
            'id_token' => $idToken,
            'idle_timeout' => static::seconds($claims['idle_timeout'] ?? null),
            'check_interval' => static::seconds($claims['access_check_interval'] ?? null),
            'checked_at' => $now,
            'active_at' => $now,
        ]);

        return redirect()->intended(config('sso.home'));
    }

    private function refused(Request $request, string $error, bool $silent): RedirectResponse
    {
        if (! $silent) {
            throw SsoException::refuse($error === 'access_denied' ? "You don't have access to this app. Ask a portal admin for access." : "The portal couldn't sign you in. Try again.");
        }

        if ($error === 'access_denied') {
            Sso::endSession($request);

            throw SsoException::refuse('You no longer have access to this app.');
        }

        // The portal session is gone: sign in again, then come back to the same page.
        if (in_array($error, ['login_required', 'interaction_required', 'consent_required', 'account_selection_required'], true)) {
            $intended = $request->session()->get('url.intended');
            Sso::endSession($request);
            $request->session()->put('url.intended', $intended);

            return redirect()->away(SignIn::url($request));
        }

        // Anything else, like temporarily_unavailable, keeps the session until the next check.
        return redirect()->intended(config('sso.home'));
    }

    private static function isOwnUrl(Request $request, string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && ! isset($parts['user'])
            && strcasecmp($parts['host'] ?? '', $request->getHost()) === 0
            && ($parts['port'] ?? null) === parse_url($request->getSchemeAndHttpHost(), PHP_URL_PORT);
    }

    private static function seconds(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
