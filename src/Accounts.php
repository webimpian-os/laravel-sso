<?php

namespace Webimpian\Sso;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

class Accounts
{
    public static function resolve(array $claims): Model
    {
        $model = Sso::userModel();

        $user = $model::query()->where('sso_sub', $claims['sub'])->first()
            ?? static::link($model, $claims)
            ?? static::create($model, $claims);

        if ($user->sso_pending) {
            throw SsoException::refuse('An admin of this app needs to approve your account before you can sign in.');
        }

        return $user;
    }

    public static function syncProfile(Model $user, array $claims): void
    {
        if (! config('sso.sync_profile')) {
            return;
        }

        $name = is_string($claims['name'] ?? null) && $claims['name'] !== '' ? $claims['name'] : null;
        $email = is_string($claims['email'] ?? null) && $claims['email'] !== '' ? $claims['email'] : null;

        if ($email !== null && Str::lower((string) $user->email) !== Str::lower($email)
            && $user->newQuery()->whereKeyNot($user->getKey())->whereRaw('lower(email) = ?', [Str::lower($email)])->exists()) {
            Log::warning('SSO kept the old email because another account in this app has the new one', ['user_id' => $user->getKey()]);
            $email = null;
        }

        // Some users tables have no name column.
        $columns = array_flip($user->getConnection()->getSchemaBuilder()->getColumnListing($user->getTable()));
        $user->forceFill(array_intersect_key(array_filter(['name' => $name, 'email' => $email], fn (?string $value) => $value !== null), $columns));

        if ($user->isDirty()) {
            $user->save();
        }
    }

    public static function syncRoles(Model $user, array $claims): void
    {
        $map = Sso::roleMap();
        $default = config('sso.default_role');

        if ($map === []) {
            if ($user->wasRecentlyCreated && filled($default)) {
                static::give($user, [$default]);
            }

            return;
        }

        $roles = array_values(array_unique(array_filter(array_map(fn ($role) => $map[$role] ?? null, (array) ($claims['roles'] ?? [])))));

        static::give($user, $roles === [] && filled($default) ? [$default] : $roles);
    }

    // First sign-in only: later ones match on sub, so a reused email can't take over an account.
    private static function link(string $model, array $claims): ?Model
    {
        $column = config('sso.workos_id_column');
        $workosId = $claims['workos_user_id'] ?? null;
        $email = $claims['email'] ?? null;

        $user = filled($column) && is_string($workosId) ? $model::query()->where($column, $workosId)->first() : null;
        $user ??= is_string($email) ? $model::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first() : null;

        if ($user === null) {
            return null;
        }

        if ($user->sso_sub !== null) {
            throw SsoException::refuse('This email belongs to another account in this app. Ask an admin of this app to sort it out.');
        }

        $user->forceFill(['sso_sub' => $claims['sub']])->save();

        return $user;
    }

    private static function create(string $model, array $claims): Model
    {
        $mode = config('sso.provisioning');

        if (! in_array($mode, ['auto', 'pending-approval'], true)) {
            throw SsoException::refuse("You don't have an account in this app yet. Ask an admin of this app to add you.");
        }

        $user = Sso::$createUser ? (Sso::$createUser)($claims) : static::newUser($model, $claims);
        $user->forceFill(['sso_sub' => $claims['sub'], 'sso_pending' => $mode === 'pending-approval'])->save();

        return $user;
    }

    private static function newUser(string $model, array $claims): Model
    {
        $user = new $model;
        $user->forceFill(['name' => $claims['name'] ?? $claims['email'] ?? 'Staff', 'email' => $claims['email'] ?? null]);

        // Never used: sign-in is through the portal.
        if ($user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), 'password')) {
            $user->forceFill(['password' => Hash::make(Str::random(40))]);
        }

        return $user;
    }

    private static function give(Model $user, array $roles): void
    {
        if (Sso::$syncRoles) {
            (Sso::$syncRoles)($user, $roles);
        } elseif (method_exists($user, 'syncRoles')) {
            $user->syncRoles($roles);
        } else {
            throw new LogicException('Set Sso::syncRolesUsing() so the package can give roles, or unset SSO_ROLE_MAP and SSO_DEFAULT_ROLE.');
        }
    }
}
