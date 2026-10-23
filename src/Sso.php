<?php

namespace Webimpian\Sso;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Webimpian\Sso\Testing\FakePortal;

class Sso
{
    public static ?Closure $resolveUser = null;

    public static ?Closure $createUser = null;

    public static ?Closure $syncRoles = null;

    // fn (array $claims): User; call Accounts::resolve() inside to keep the default matching.
    public static function resolveUsersUsing(?Closure $callback): void
    {
        static::$resolveUser = $callback;
    }

    // fn (array $claims): User builds the unsaved account for a first sign-in.
    public static function createUsersUsing(?Closure $callback): void
    {
        static::$createUser = $callback;
    }

    // fn (User $user, array $roles) gives the mapped roles; models with syncRoles() need none.
    public static function syncRolesUsing(?Closure $callback): void
    {
        static::$syncRoles = $callback;
    }

    public static function addStaff(string $email, string $firstName, ?string $lastName = null): array
    {
        return app(Portal::class)->addStaff($email, $firstName, $lastName);
    }

    public static function fake(array $claims = []): FakePortal
    {
        $fake = new FakePortal($claims);

        app()->instance(Portal::class, $fake);

        return $fake;
    }

    // True when this session was signed in through the portal, not a local login.
    public static function check(): bool
    {
        $request = request();

        return $request->hasSession() && is_array($request->session()->get('sso')) && static::guard()->check();
    }

    public static function guard(): StatefulGuard
    {
        return Auth::guard(config('sso.guard'));
    }

    public static function userModel(): string
    {
        $guard = config('sso.guard') ?? config('auth.defaults.guard');

        return config('auth.providers.'.config("auth.guards.{$guard}.provider").'.model');
    }

    public static function roleMap(): array
    {
        $map = config('sso.role_map');

        if (is_array($map)) {
            return $map;
        }

        $roles = [];

        foreach (explode(',', (string) $map) as $pair) {
            [$portalRole, $appRole] = array_map('trim', array_pad(explode(':', $pair, 2), 2, ''));

            if ($portalRole !== '' && $appRole !== '') {
                $roles[$portalRole] = $appRole;
            }
        }

        return $roles;
    }

    public static function endSession(Request $request): void
    {
        static::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public static function endedKey(string $sid): string
    {
        return 'sso.ended.'.hash('sha256', $sid);
    }
}
