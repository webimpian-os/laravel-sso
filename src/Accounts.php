<?php

namespace Webimpian\Sso;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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

        $column = config('sso.staff.revoked_column');

        if (config('sso.staff.enabled') && filled($column) && static::hasColumn($user, $column) && $user->{$column} !== null) {
            throw SsoException::refuse('Your access to this app was removed.');
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

    public static function forStaff(array $claims): array
    {
        $model = Sso::userModel();
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);

        $user = ($softDeletes ? $model::withTrashed() : $model::query())->where('sso_sub', $claims['sub'])->first();
        $created = false;

        if ($user !== null && method_exists($user, 'trashed') && $user->trashed()) {
            $user->restore();
        }

        if ($user === null) {
            $user = static::findUnlinked($model::query(), $claims, requireVerifiedEmail: true);

            // A deleted row matched by email or WorkOS ID comes back only when the portal asks.
            if ($user === null && $softDeletes) {
                $restore = ($claims['restore_deleted'] ?? false) === true;
                $trashed = static::findUnlinked($model::onlyTrashed(), $claims, requireVerifiedEmail: $restore);

                if ($trashed !== null && ! $restore) {
                    throw SsoException::deletedAccount();
                }

                $user = $trashed;
            }

            if ($user !== null) {
                static::claim($user, $claims);

                if (method_exists($user, 'trashed') && $user->trashed()) {
                    $user->restore();
                }
            } else {
                $user = static::make($model, $claims);
                $user->forceFill(['sso_sub' => $claims['sub']]);
                $created = true;
            }
        }

        // An SSO assignment is the approval.
        $user->forceFill(['sso_pending' => false])->save();

        return [$user, $created];
    }

    // First sign-in only: later ones match on sub, so a reused email can't take over an account.
    private static function link(string $model, array $claims): ?Model
    {
        // Only with staff sync on, so apps without it link as they did in v1.0.
        $user = static::findUnlinked($model::query(), $claims, requireVerifiedEmail: (bool) config('sso.staff.enabled'));

        if ($user === null) {
            return null;
        }

        static::claim($user, $claims);

        return $user;
    }

    // Who an apply would link, without linking them.
    public static function staffMatch(array $claims): ?Model
    {
        return static::findUnlinked(Sso::userModel()::query()->whereNull('sso_sub'), $claims, requireVerifiedEmail: true);
    }

    private static function findUnlinked(Builder $query, array $claims, bool $requireVerifiedEmail = false): ?Model
    {
        $column = config('sso.workos_id_column');
        $workosId = $claims['workos_user_id'] ?? null;
        $email = $claims['email'] ?? null;

        $user = filled($column) && is_string($workosId) ? (clone $query)->where($column, $workosId)->first() : null;

        if ($user === null && is_string($email)) {
            $user = (clone $query)->whereRaw('lower(email) = ?', [Str::lower($email)])->first();

            // An unverified address may belong to someone else.
            if ($user !== null && $requireVerifiedEmail && static::hasColumn($user, 'email_verified_at') && $user->email_verified_at === null) {
                throw SsoException::refuse("This email isn't verified in this app, so it can't be linked. Ask an admin of this app to verify it.");
            }
        }

        return $user;
    }

    private static function claim(Model $user, array $claims): void
    {
        if ($user->sso_sub !== null) {
            throw SsoException::refuse('This email belongs to another account in this app. Ask an admin of this app to sort it out.');
        }

        $user->forceFill(['sso_sub' => $claims['sub']])->save();
    }

    public static function hasColumn(Model $user, string $column): bool
    {
        return $user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), $column);
    }

    private static function create(string $model, array $claims): Model
    {
        $mode = config('sso.provisioning');

        if (! in_array($mode, ['auto', 'pending-approval'], true)) {
            throw SsoException::refuse("You don't have an account in this app yet. Ask an admin of this app to add you.");
        }

        $user = static::make($model, $claims);
        $user->forceFill(['sso_sub' => $claims['sub'], 'sso_pending' => $mode === 'pending-approval'])->save();

        return $user;
    }

    private static function make(string $model, array $claims): Model
    {
        return Sso::$createUser ? (Sso::$createUser)($claims) : static::newUser($model, $claims);
    }

    private static function newUser(string $model, array $claims): Model
    {
        $user = new $model;
        $user->forceFill(['name' => $claims['name'] ?? $claims['email'] ?? 'Staff', 'email' => $claims['email'] ?? null]);

        // Never used: sign-in is through the portal.
        if (static::hasColumn($user, 'password')) {
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
