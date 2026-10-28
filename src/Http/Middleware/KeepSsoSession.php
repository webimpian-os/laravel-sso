<?php

namespace Webimpian\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Webimpian\Sso\Portal;
use Webimpian\Sso\SignIn;
use Webimpian\Sso\Sso;

class KeepSsoSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $sso = $request->hasSession() ? $request->session()->get('sso') : null;

        if (! is_array($sso) || ($request->routeIs('sso.*') && ! $request->routeIs('sso.active')) || ! Sso::guard()->check()) {
            return $next($request);
        }

        $now = now()->getTimestamp();
        $idle = isset($sso['idle_timeout']) && $now - $sso['active_at'] > $sso['idle_timeout'];

        if ($idle || (isset($sso['sid']) && Cache::has(Sso::endedKey($sso['sid']))) || (isset($sso['sub']) && Cache::has(Sso::revokedKey($sso['sub'])))) {
            return $this->signInAgain($request, $idle);
        }

        $request->session()->put('sso.active_at', $now);

        if (isset($sso['check_interval']) && $now - $sso['checked_at'] >= $sso['check_interval'] && static::isPageLoad($request)) {
            // Set first, so an outage or an abandoned check waits a full interval.
            $request->session()->put('sso.checked_at', $now);

            if (app(Portal::class)->reachable()) {
                $request->session()->put('url.intended', $request->fullUrl());

                return static::away($request, SignIn::url($request, 'none'));
            }
        }

        return $next($request);
    }

    private function signInAgain(Request $request, bool $idle): Response
    {
        $intended = $request->isMethod('GET') ? $request->fullUrl() : url()->previous();

        Sso::endSession($request);
        $request->session()->put('url.intended', $intended);

        // Coming back after walking away takes a real sign-in, not the portal's instant one.
        if ($idle) {
            $request->session()->put('sso_prompt', 'login');
        }

        // 419 is what Livewire and Laravel front ends already treat as an expired page.
        if (($request->ajax() || $request->expectsJson()) && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => 'Your session has ended. Sign in again.'], 419);
        }

        return static::away($request, route('sso.login'));
    }

    // Never a form post or background request, so nothing typed is lost.
    private static function isPageLoad(Request $request): bool
    {
        return $request->isMethod('GET')
            && $request->header('Purpose') !== 'prefetch'
            && ! $request->hasHeader('X-Inertia-Partial-Component')
            && ($request->hasHeader('X-Inertia') || ! ($request->ajax() || $request->expectsJson()));
    }

    // Inertia can't follow a redirect to another site from XHR, so it gets a full page visit.
    private static function away(Request $request, string $url): Response
    {
        return $request->hasHeader('X-Inertia') ? response('', 409, ['X-Inertia-Location' => $url]) : redirect()->away($url);
    }
}
