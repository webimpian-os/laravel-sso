<?php

use Illuminate\Support\Facades\Cache;
use Webimpian\Sso\Portal;
use Webimpian\Sso\Sso;
use Webimpian\Sso\SsoException;
use Webimpian\Sso\Tests\SoftUser;
use Webimpian\Sso\Tests\User;

it('reads a valid staff token', function () {
    expect(app(Portal::class)->staffClaims($this->staffToken())['action'])->toBe('levels');
});

it('refuses tokens that are not staff notices', function (Closure $make) {
    expect(fn () => app(Portal::class)->staffClaims(Closure::bind($make, $this, $this)()))->toThrow(SsoException::class);
})->with([
    'logout token' => [fn () => $this->logoutToken()],
    'no typ' => [fn () => $this->sign(['iss' => 'https://portal.test', 'aud' => 'app-client', 'iat' => time(), 'exp' => time() + 60, 'jti' => 'j1', 'action' => 'levels'])],
    'other audience' => [fn () => $this->staffToken(['aud' => 'other-client'])],
    'expired' => [fn () => $this->staffToken(['exp' => time() - 120])],
    'lives too long' => [fn () => $this->staffToken(['iat' => time(), 'exp' => time() + 300])],
    'unknown action' => [fn () => $this->staffToken(['action' => 'promote'])],
]);

it('refuses a replayed staff token', function () {
    $token = $this->staffToken();
    app(Portal::class)->staffClaims($token);

    expect(fn () => app(Portal::class)->staffClaims($token))->toThrow(SsoException::class);
});

it('is off until the app turns it on', function () {
    $this->post('auth/sso/staff', ['staff_token' => $this->staffToken()])->assertNotFound();
});

it('refuses a bad staff token', function () {
    config(['sso.staff.enabled' => true]);

    $this->post('auth/sso/staff', ['staff_token' => 'nope'])->assertStatus(400)->assertExactJson(['error' => 'invalid_request']);
});

it('lists the levels the app offers', function () {
    config(['sso.staff.enabled' => true]);
    Sso::staffLevelsUsing(fn () => ['super_admin' => 'Super admin', 'team' => 'Team']);

    $this->post('auth/sso/staff', ['staff_token' => $this->staffToken()])->assertOk()->assertExactJson([
        'levels' => [['id' => 'super_admin', 'name' => 'Super admin'], ['id' => 'team', 'name' => 'Team']],
        'deletes' => false,
    ]);

    config(['sso.staff.deletes' => true]);

    $this->post('auth/sso/staff', ['staff_token' => $this->staffToken()])->assertOk()->assertJsonPath('deletes', true);
});

function applyStaff($test, array $claims = [], &$createdFlag = null)
{
    config(['sso.staff.enabled' => true]);
    Sso::staffLevelsUsing(fn () => ['admin' => 'Admin']);
    Sso::applyStaffUsing(function ($user, $level, $created) use (&$createdFlag) {
        $user->forceFill(['role' => $level])->save();
        $createdFlag = $created;
    });

    return $test->post('auth/sso/staff', ['staff_token' => Closure::bind(fn () => $this->staffToken(['action' => 'apply', 'sub' => 'sub-1', 'email' => 'aina@example.com', 'name' => 'Aina', 'level' => 'admin', ...$claims]), $test, $test)()]);
}

it('applies a level to the user found by sub', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);

    applyStaff($this)->assertOk()->assertExactJson(['status' => 'applied', 'level' => 'admin']);

    expect($user->fresh()->role)->toBe('admin');
});

it('links an unlinked row by verified email', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'Aina@example.com', 'email_verified_at' => now()]);

    applyStaff($this)->assertOk();

    expect($user->fresh()->sso_sub)->toBe('sub-1');
});

it('refuses to link an unverified email', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com']);

    applyStaff($this)->assertStatus(422)->assertJsonPath('status', 'refused');
    expect(User::first()->sso_sub)->toBeNull();
    expect(applyStaff($this)->json('reason'))->toContain("isn't verified")->toEndWith('Ask an admin of this app to verify it.');
});

it('creates a new user', function () {
    applyStaff($this, [], $created)->assertOk();

    expect($created)->toBeTrue()
        ->and(User::where('sso_sub', 'sub-1')->first()->role)->toBe('admin');
});

it('restores a soft-deleted user with that sub', function () {
    config(['auth.providers.users.model' => SoftUser::class]);
    $user = SoftUser::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);
    $user->delete();

    applyStaff($this)->assertOk();

    expect(SoftUser::count())->toBe(1)
        ->and(SoftUser::first()->id)->toBe($user->id)
        ->and(SoftUser::first()->deleted_at)->toBeNull();
});

it('clears the revoked mark and cache', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1', 'access_revoked_at' => now()]);
    Cache::put(Sso::revokedKey('sub-1'), true, 60);

    applyStaff($this)->assertOk();

    expect($user->fresh()->access_revoked_at)->toBeNull()->and(Cache::has(Sso::revokedKey('sub-1')))->toBeFalse();
});

it('refuses a level the app does not offer', function () {
    applyStaff($this, ['level' => 'owner'])->assertStatus(422);
    expect(User::count())->toBe(0);
});

it('refuses when the app has no apply callback', function () {
    config(['sso.staff.enabled' => true]);

    $this->post('auth/sso/staff', ['staff_token' => $this->staffToken(['action' => 'apply', 'sub' => 'sub-1', 'level' => 'admin'])])
        ->assertStatus(422)->assertJsonPath('reason', "This app can't take staff from SSO yet.");
});

it('refuses a notice without a usable sub', function (array $claims) {
    expect(fn () => app(Portal::class)->staffClaims($this->staffToken($claims)))->toThrow(SsoException::class);
})->with([
    'apply no sub' => [['action' => 'apply']],
    'remove empty sub' => [['action' => 'remove', 'sub' => '']],
    'delete array sub' => [['action' => 'delete', 'sub' => ['x']]],
    'profile null sub' => [['action' => 'profile', 'sub' => null]],
    'status no subs' => [['action' => 'status']],
    'status non-string subs' => [['action' => 'status', 'subs' => ['a', 1]]],
]);

it('does not link a random unlinked user when the sub is missing', function () {
    User::create(['name' => 'Other', 'email' => 'other@example.com']);

    applyStaff($this, ['sub' => null])->assertStatus(400);
    expect(User::first()->sso_sub)->toBeNull();
});

it('does not restore a deleted row matched by email', function () {
    config(['auth.providers.users.model' => SoftUser::class]);
    $user = SoftUser::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now()]);
    $user->delete();

    applyStaff($this)->assertStatus(422)->assertJsonPath('reason', 'This email belongs to a deleted account in this app. Ask an admin of this app to sort it out.');
    expect(SoftUser::withTrashed()->first()->trashed())->toBeTrue()->and(SoftUser::withTrashed()->first()->sso_sub)->toBeNull();
});

it('tags the deleted-account refusal with a code', function () {
    config(['auth.providers.users.model' => SoftUser::class]);
    SoftUser::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now()])->delete();

    applyStaff($this)->assertStatus(422)->assertJsonPath('code', 'deleted_account');
});

it('gives no code to other refusals', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now(), 'sso_sub' => 'other']);

    expect(applyStaff($this)->assertStatus(422)->json())->not->toHaveKey('code');
});

it('restores a deleted row when asked to', function () {
    config(['auth.providers.users.model' => SoftUser::class]);
    $user = SoftUser::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now()]);
    $user->delete();

    applyStaff($this, ['restore_deleted' => true])->assertOk();

    $fresh = SoftUser::withTrashed()->sole();
    expect($fresh->id)->toBe($user->id)->and($fresh->deleted_at)->toBeNull()->and($fresh->sso_sub)->toBe('sub-1')->and($fresh->role)->toBe('admin');
});

it('only restores for a strict true', function (mixed $flag) {
    config(['auth.providers.users.model' => SoftUser::class]);
    SoftUser::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now()])->delete();

    applyStaff($this, ['restore_deleted' => $flag])->assertStatus(422)->assertJsonPath('code', 'deleted_account');
    expect(SoftUser::withTrashed()->sole()->trashed())->toBeTrue();
})->with(['string' => ['true'], 'one' => [1], 'false' => [false]]);

it('does not restore a deleted row with an unverified email', function () {
    config(['auth.providers.users.model' => SoftUser::class]);
    SoftUser::create(['name' => 'Aina', 'email' => 'aina@example.com'])->delete();

    applyStaff($this, ['restore_deleted' => true])->assertStatus(422)->assertJsonPath('reason', "This email isn't verified in this app, so it can't be linked. Ask an admin of this app to verify it.");
    expect(SoftUser::withTrashed()->sole()->trashed())->toBeTrue();
});

it('does not restore a deleted row linked to another sub', function () {
    config(['auth.providers.users.model' => SoftUser::class]);
    SoftUser::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now(), 'sso_sub' => 'other'])->delete();

    applyStaff($this, ['restore_deleted' => true])->assertStatus(422)->assertJsonPath('status', 'refused');
    expect(SoftUser::withTrashed()->sole()->trashed())->toBeTrue()->and(SoftUser::withTrashed()->sole()->sso_sub)->toBe('other');
});

it('links a row by WorkOS id', function () {
    config(['sso.workos_id_column' => 'workos_id']);
    $user = User::create(['name' => 'Aina', 'email' => 'old@example.com', 'workos_id' => 'wo-1']);

    applyStaff($this, ['workos_user_id' => 'wo-1'])->assertOk();

    expect($user->fresh()->sso_sub)->toBe('sub-1');
});

it('refuses a row already linked to another sub', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now(), 'sso_sub' => 'other']);

    applyStaff($this)->assertStatus(422)->assertJsonPath('status', 'refused');
});

it('leaves nothing behind when the callback refuses', function () {
    $other = User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now()]);
    applyStaff($this);
    User::where('sso_sub', 'sub-1')->update(['sso_sub' => null]);
    Sso::applyStaffUsing(fn () => throw SsoException::refuse('x'));

    $post = fn (array $claims) => $this->post('auth/sso/staff', ['staff_token' => Closure::bind(fn () => $this->staffToken(['action' => 'apply', 'level' => 'admin', ...$claims]), $this, $this)()]);

    $post(['sub' => 'sub-2', 'email' => 'aina@example.com'])->assertStatus(422)->assertJsonPath('reason', 'x');
    $post(['sub' => 'sub-3', 'email' => 'new@example.com'])->assertStatus(422);

    expect($other->fresh()->sso_sub)->toBeNull()->and(User::count())->toBe(1);
});

it('clears the pending flag on every path', function () {
    $found = User::create(['name' => 'A', 'email' => 'a@example.com', 'sso_sub' => 'sub-1', 'sso_pending' => true]);
    applyStaff($this)->assertOk();
    expect($found->fresh()->sso_pending)->toBeFalsy();

    $new = applyStaff($this, ['sub' => 'sub-9', 'email' => 'n@example.com']);
    expect(User::where('sso_sub', 'sub-9')->first()->sso_pending)->toBeFalsy();
});

function notice($test, string $action, array $claims = [])
{
    config(['sso.staff.enabled' => true]);

    return $test->post('auth/sso/staff', ['staff_token' => Closure::bind(fn () => $this->staffToken(['action' => $action, ...$claims]), $test, $test)()]);
}

it('removes by marking the revoked column and ends the open session', function () {
    config(['sso.staff.enabled' => true]);
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'email_verified_at' => now()]);
    $this->signIn(['sub' => 'sub-1']);
    $this->get('dashboard')->assertOk();

    notice($this, 'remove', ['sub' => 'sub-1'])->assertOk()->assertExactJson(['status' => 'removed']);

    expect(User::first()->access_revoked_at)->not->toBeNull()->and(Cache::has(Sso::revokedKey('sub-1')))->toBeTrue();
    $this->get('dashboard')->assertRedirect(route('sso.login'));
});

it('runs the remove callback instead of the default', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);
    Sso::removeStaffUsing(fn ($u) => $u->forceFill(['role' => 'gone'])->save());

    notice($this, 'remove', ['sub' => 'sub-1'])->assertOk();

    expect($user->fresh()->role)->toBe('gone')->and($user->fresh()->access_revoked_at)->toBeNull()->and(Cache::has(Sso::revokedKey('sub-1')))->toBeTrue();
});

it('refuses the default remove when the revoked column is missing', function () {
    config(['sso.staff.revoked_column' => 'missing_column']);
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);

    notice($this, 'remove', ['sub' => 'sub-1'])->assertStatus(422)->assertJsonPath('reason', 'This app has no way to remove staff yet.');
});

it('answers removed for a sub it does not know', function () {
    notice($this, 'remove', ['sub' => 'nobody'])->assertOk()->assertExactJson(['status' => 'removed']);
    expect(User::count())->toBe(0);
});

it('refuses sign-in for someone whose access was removed', function () {
    config(['sso.staff.enabled' => true]);
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'staff-uuid-1', 'access_revoked_at' => now()]);

    $this->signIn()->assertForbidden()->assertSee('Your access to this app was removed.');

    $this->assertGuest();
});

it('refuses deletes while the app has them off', function () {
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);

    notice($this, 'delete', ['sub' => 'sub-1'])->assertStatus(422)->assertJsonPath('reason', "This app doesn't allow deletes from SSO.");
    expect(User::count())->toBe(1);
});

it('soft deletes when deletes are on', function () {
    config(['auth.providers.users.model' => SoftUser::class, 'sso.staff.deletes' => true]);
    SoftUser::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);

    notice($this, 'delete', ['sub' => 'sub-1'])->assertOk()->assertExactJson(['status' => 'deleted']);

    expect(SoftUser::count())->toBe(0)->and(SoftUser::withTrashed()->count())->toBe(1)->and(Cache::has(Sso::revokedKey('sub-1')))->toBeTrue();
});

it('answers deleted for a sub it does not know', function () {
    config(['sso.staff.deletes' => true]);

    notice($this, 'delete', ['sub' => 'nobody'])->assertOk()->assertExactJson(['status' => 'deleted']);
});

it('refuses to delete without soft deletes or a callback', function () {
    config(['sso.staff.deletes' => true]);
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);

    notice($this, 'delete', ['sub' => 'sub-1'])->assertStatus(422)->assertJsonPath('reason', "This app doesn't delete staff.");
    expect(User::count())->toBe(1);
});

it('lets the delete callback refuse with its own reason', function () {
    config(['sso.staff.deletes' => true]);
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);
    Sso::deleteStaffUsing(fn () => throw SsoException::refuse('Has 120 reconciliations'));

    notice($this, 'delete', ['sub' => 'sub-1'])->assertStatus(422)->assertJsonPath('reason', 'Has 120 reconciliations');
});

it('updates name and email on a profile notice', function () {
    $user = User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);

    notice($this, 'profile', ['sub' => 'sub-1', 'name' => 'Aina Binti', 'email' => 'new@example.com'])->assertOk()->assertExactJson(['status' => 'updated']);

    expect($user->fresh()->name)->toBe('Aina Binti')->and($user->fresh()->email)->toBe('new@example.com');
});

it('answers missing to a profile notice for an unknown sub', function () {
    notice($this, 'profile', ['sub' => 'nobody', 'name' => 'X'])->assertOk()->assertExactJson(['status' => 'missing']);
    expect(User::count())->toBe(0);
});

it('reports status for each sub', function () {
    Sso::staffLevelsUsing(fn () => ['admin' => 'Admin']);
    Sso::staffLevelUsing(fn ($user) => 'admin');
    User::create(['name' => 'A', 'email' => 'a@example.com', 'sso_sub' => 'linked']);
    User::create(['name' => 'B', 'email' => 'b@example.com', 'sso_sub' => 'gone', 'access_revoked_at' => now()]);

    notice($this, 'status', ['subs' => ['linked', 'gone', 'nobody']])->assertOk()->assertExactJson(['people' => [
        'linked' => ['status' => 'active', 'level' => 'admin', 'level_name' => 'Admin'],
        'gone' => ['status' => 'removed', 'level' => null, 'level_name' => null],
        'nobody' => ['status' => 'missing', 'level' => null, 'level_name' => null],
    ]]);
});

it('falls back to the level id when the levels list lacks it', function () {
    Sso::staffLevelUsing(fn ($user) => 'legacy');
    User::create(['name' => 'A', 'email' => 'a@example.com', 'sso_sub' => 'linked']);

    notice($this, 'status', ['subs' => ['linked']])->assertJsonPath('people.linked.level_name', 'legacy');
});

it('answers the fake portal token as plain JSON', function () {
    config(['sso.staff.enabled' => true]);
    Sso::fake();

    $this->post('auth/sso/staff', ['staff_token' => json_encode(['action' => 'levels'])])->assertOk()->assertJsonPath('deletes', false);
});

it('lets a removed person back in when the callback does not block sign-in', function () {
    config(['sso.staff.enabled' => true]);
    User::create(['name' => 'Aina', 'email' => 'aina@example.com', 'sso_sub' => 'sub-1']);
    Sso::removeStaffUsing(fn ($u) => $u->forceFill(['role' => 'agent'])->save());

    notice($this, 'remove', ['sub' => 'sub-1'])->assertOk();
    expect(Cache::has(Sso::revokedKey('sub-1')))->toBeTrue();

    $this->signIn(['sub' => 'sub-1']);

    expect(Cache::has(Sso::revokedKey('sub-1')))->toBeFalse();
    $this->get('dashboard')->assertOk();
});

it('reports the level as a string', function () {
    config(['sso.staff.enabled' => true]);
    User::create(['name' => 'A', 'email' => 'a@example.com', 'sso_sub' => 'linked']);
    Sso::staffLevelUsing(fn ($user) => 1);

    notice($this, 'status', ['subs' => ['linked']])->assertJsonPath('people.linked.level', '1');
});
