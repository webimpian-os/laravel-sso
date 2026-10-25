<?php

namespace Webimpian\Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Webimpian\Sso\Accounts;
use Webimpian\Sso\Portal;
use Webimpian\Sso\Sso;
use Webimpian\Sso\SsoException;

class StaffNoticeController
{
    public function __invoke(Request $request, Portal $portal): JsonResponse
    {
        // Stays registered so the config toggle decides, not route caching.
        abort_unless(config('sso.staff.enabled'), 404);

        $token = $request->input('staff_token');

        try {
            $claims = $portal->staffClaims(is_string($token) ? $token : '');
        } catch (SsoException) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        try {
            return match ($claims['action']) {
                'levels' => $this->levels(),
                'apply' => $this->apply($claims),
                'remove' => $this->remove($claims),
                'delete' => $this->delete($claims),
                'profile' => $this->profile($claims),
                'status' => $this->status($claims),
                default => abort(404),
            };
        } catch (SsoException $e) {
            return $this->json(['status' => 'refused', 'reason' => $e->getMessage(), ...($e->reasonCode === null ? [] : ['code' => $e->reasonCode])], 422);
        }
    }

    private function levels(): JsonResponse
    {
        $levels = [];

        foreach ((Sso::$staffLevels ?? fn () => [])() as $id => $name) {
            $levels[] = ['id' => (string) $id, 'name' => $name];
        }

        return $this->json(['levels' => $levels, 'deletes' => (bool) config('sso.staff.deletes')]);
    }

    private function apply(array $claims): JsonResponse
    {
        if (Sso::$applyStaff === null) {
            throw SsoException::refuse("This app can't take staff from SSO yet.");
        }

        $level = (string) ($claims['level'] ?? '');

        if (! array_key_exists($level, (Sso::$staffLevels ?? fn () => [])())) {
            throw SsoException::refuse("This app doesn't offer that level.");
        }

        $connection = (new (Sso::userModel()))->getConnection();

        $connection->transaction(function () use ($claims, $level) {
            [$user, $created] = Accounts::forStaff($claims);

            (Sso::$applyStaff)($user, $level, $created);

            $column = config('sso.staff.revoked_column');

            if (filled($column) && Accounts::hasColumn($user, $column) && $user->{$column} !== null) {
                $user->forceFill([$column => null])->save();
            }
        });

        Cache::forget(Sso::revokedKey($claims['sub']));

        return $this->json(['status' => 'applied', 'level' => $level]);
    }

    private function remove(array $claims): JsonResponse
    {
        $user = $this->find($claims['sub']);

        if ($user !== null) {
            $column = config('sso.staff.revoked_column');

            if (Sso::$removeStaff === null && (blank($column) || ! Accounts::hasColumn($user, $column))) {
                throw SsoException::refuse('This app has no way to remove staff yet.');
            }

            if (Sso::$removeStaff !== null) {
                (Sso::$removeStaff)($user);
            } else {
                $user->forceFill([$column => now()])->save();
            }
        }

        // Even for an unknown sub, so a later link can't revive a session the portal ended.
        Cache::put(Sso::revokedKey($claims['sub']), true, now()->addDays(30));

        return $this->json(['status' => 'removed']);
    }

    private function delete(array $claims): JsonResponse
    {
        if (! config('sso.staff.deletes')) {
            throw SsoException::refuse("This app doesn't allow deletes from SSO.");
        }

        $user = $this->find($claims['sub']);

        if ($user !== null) {
            if (Sso::$deleteStaff !== null) {
                (Sso::$deleteStaff)($user);
            } elseif (in_array(SoftDeletes::class, class_uses_recursive($user), true)) {
                $user->delete();
            } else {
                throw SsoException::refuse("This app doesn't delete staff.");
            }
        }

        Cache::put(Sso::revokedKey($claims['sub']), true, now()->addDays(30));

        return $this->json(['status' => 'deleted']);
    }

    private function profile(array $claims): JsonResponse
    {
        $user = $this->find($claims['sub']);

        if ($user === null) {
            return $this->json(['status' => 'missing']);
        }

        Accounts::syncProfile($user, $claims);

        return $this->json(['status' => 'updated']);
    }

    private function status(array $claims): JsonResponse
    {
        $names = (Sso::$staffLevels ?? fn () => [])();
        $people = [];

        foreach (array_unique($claims['subs']) as $sub) {
            $user = $this->find($sub);
            $status = $user === null ? 'missing' : ($this->isRemoved($user, $sub) ? 'removed' : 'active');
            $level = $status === 'active' && Sso::$staffLevel !== null ? (Sso::$staffLevel)($user) : null;
            $level = $level === null ? null : (string) $level;

            $people[$sub] = [
                'status' => $status,
                'level' => $level,
                'level_name' => $level === null ? null : (string) ($names[$level] ?? $level),
            ];
        }

        return $this->json(['people' => (object) $people]);
    }

    private function isRemoved(Model $user, string $sub): bool
    {
        $column = config('sso.staff.revoked_column');

        if (filled($column) && Accounts::hasColumn($user, $column) && $user->{$column} !== null) {
            return true;
        }

        return Sso::$removeStaff !== null && Cache::has(Sso::revokedKey($sub));
    }

    private function find(string $sub): ?Model
    {
        return Sso::userModel()::query()->where('sso_sub', $sub)->first();
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store']);
    }
}
