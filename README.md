# Laravel SSO

[![tests](https://github.com/webimpian-os/laravel-sso/actions/workflows/tests.yml/badge.svg)](https://github.com/webimpian-os/laravel-sso/actions/workflows/tests.yml)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![Laravel 10 to 13](https://img.shields.io/badge/Laravel-10%E2%80%9313-FF2D20?logo=laravel&logoColor=white)
![Filament 3 to 5](https://img.shields.io/badge/Filament-3%E2%80%935-FDAE4B)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE.md)

Sign staff in to a Laravel app through an OpenID Connect SSO portal. The portal handles sign-in, two-step verification and who may open the app. The app keeps its own users and roles.

You need the app registered in the portal, with its client ID and secret.

## Installation

```bash
composer require webimpian/laravel-sso
php artisan migrate
```

The migration adds `sso_sub` and `sso_pending` to the users table.

Copy the `.env` block from the app's Connection tab in the portal:

```env
SSO_ISSUER=https://sso.example.com
SSO_CLIENT_ID=your-client-id
SSO_CLIENT_SECRET=your-client-secret
```

Send guests to the portal, and sign out through it:

```php
// routes/web.php
Route::redirect('login', 'auth/sso/login')->name('login');
```

```blade
<form method="POST" action="{{ route('sso.logout') }}">
    @csrf
    <button>Sign out</button>
</form>
```

Then add these URLs to the app in the portal:

| Portal field | URL |
|---|---|
| Callback URL | `https://your-app/auth/sso/callback` |
| Sign-in URL | `https://your-app/auth/sso/login` |
| Back-channel logout URL | `https://your-app/auth/sso/backchannel-logout` |

The portal calls the back-channel URL server to server, so let it through any WAF or bot protection.

## How it works

| When | What happens |
|---|---|
| First sign-in | The package links the app's existing account by WorkOS ID (with `SSO_WORKOS_ID_COLUMN`) or by email, and saves `sso_sub`. With no account, `SSO_PROVISIONING` decides. |
| Every sign-in | The account is found by `sso_sub`, never by email, and the portal's name and email are copied onto it. The portal is where they change, so make them read-only in the app. |
| Access check interval passes | The next page load checks with the portal silently. Forms and background requests are never interrupted. |
| Access removed, revoked, or signed out in the portal | The app session ends. |
| Idle past the app's timeout | Coming back needs a full sign-in. |
| Portal unreachable | Staff stay signed in, and the next check tries again. |

The idle timeout and check interval are set per app in the portal. Sessions that didn't come from the portal, such as a break-glass admin login, are left alone.

## Accounts

`SSO_PROVISIONING` decides what a first sign-in without an account gets:

| Mode | What happens |
|---|---|
| `existing-only` (default) | "Ask an admin of this app to add you" |
| `auto` | The account is created, with `SSO_DEFAULT_ROLE` if set |
| `pending-approval` | The account is created but held until `php artisan sso:approve someone@example.com` |

To change how accounts are found or created, set callbacks in `AppServiceProvider::boot()`:

```php
use Webimpian\Sso\Accounts;
use Webimpian\Sso\Sso;
use Webimpian\Sso\SsoException;

Sso::createUsersUsing(fn (array $claims) => (new User)->forceFill([
    'name' => $claims['name'],
    'email' => $claims['email'],
    'user_type' => UserType::Staff,
]));

Sso::resolveUsersUsing(function (array $claims) {
    $user = Accounts::resolve($claims);

    if ($user->suspended_at !== null) {
        throw SsoException::refuse('Your access to this app was removed.');
    }

    return $user;
});
```

The claims are `sub`, `sid`, `name`, `email` and `picture`, plus `roles` and `workos_user_id` with the `staff` scope.

## Roles

Roles stay in the app. To copy the portal's WorkOS roles on every sign-in, map them:

```env
SSO_ROLE_MAP=admin:admin,member:viewer
SSO_DEFAULT_ROLE=viewer
```

Models with `syncRoles()` (Spatie Permission) work as they are. Otherwise, tell the package how to give roles:

```php
Sso::syncRolesUsing(fn (User $user, array $roles) => $user->update(['role' => $roles[0] ?? 'viewer']));
```

A role map or `SSO_WORKOS_ID_COLUMN` adds the `staff` scope, which an admin must allow on the app's Access tab.

## Adding staff from the app

Only for apps the portal doesn't manage (`SSO_STAFF_SYNC` off). With staff managed from the portal, people are added and removed there instead.

An app can add and remove its own staff once an admin turns on **Can manage staff** on its Access tab.

```php
$staff = Sso::addStaff('aina@example.com', 'Aina', 'Rahman');
User::create(['name' => $staff['name'], 'email' => $staff['email'], 'sso_sub' => $staff['sub']]);

Sso::removeStaff($user->sso_sub);
```

- `addStaff()` creates new people in WorkOS and the portal, and finds people already on staff. Nobody is emailed, so tell them to sign in.
- For an app with an access list, `addStaff()` adds them to it, and `removeStaff()` takes them off and signs them out of the app at once.
- For an app open to all staff, or by role or group, that access stays with portal admins, and `access_reason` says so.
- People outside the company open only the apps whose list names them. `addStaff()` can't create them, but both calls add or remove an existing one on this app's list, whatever its access mode.
- Refusals throw `SsoException` with the portal's reason and status: 409 revoked, 422 not a company address, 503 WorkOS down.

## Revoked staff

When a portal admin revokes someone, the portal deactivates their WorkOS membership, signs them out of every app, and tells each app they used or are listed on. The package ends their sessions and fires an event, so the app can suspend the account itself:

```php
use Webimpian\Sso\Events\StaffRestored;
use Webimpian\Sso\Events\StaffRevoked;

Event::listen(StaffRevoked::class, fn (StaffRevoked $event) => $event->user->update(['suspended_at' => now()]));
Event::listen(StaffRestored::class, fn (StaffRestored $event) => $event->user->update(['suspended_at' => null]));
```

The notice comes through the back-channel logout URL. Without a listener, the account stays as it is and the person can't sign in.

## Staff managed from the portal

The portal can add people to the app, set their level, update their name and email, and remove them. It posts signed notices to `POST /auth/sso/staff`, on the same host as the app's sign-in URL. This is off by default:

```env
SSO_STAFF_SYNC=true
# SSO_STAFF_DELETE=true   # a separate opt-in: let the portal delete accounts
```

Tell the package the app's levels and what each notice does, in `AppServiceProvider::boot()`:

```php
Sso::staffLevelsUsing(fn () => ['admin' => 'Admin', 'agent' => 'Agent']);

Sso::applyStaffUsing(fn (User $user, string $level, bool $created) => $user->forceFill([
    'role' => $level,
    'team_id' => $created ? Team::default()->id : $user->team_id,
])->save());

Sso::staffLevelUsing(fn (User $user) => $user->role);

Sso::removeStaffUsing(fn (User $user) => $user->update(['suspended_at' => now()]));

Sso::deleteStaffUsing(function (User $user) {
    if ($user->orders()->exists()) {
        throw SsoException::refuse('This person has orders. Remove them instead.');
    }

    $user->delete(); // a soft delete: the model uses SoftDeletes
});
```

| Notice | What the package does |
|---|---|
| Apply a level | Finds the person by `sso_sub` (restoring a soft-deleted row), then the WorkOS ID column, then a verified email, or creates them. Runs `applyStaffUsing()`, and clears `sso_pending` and the revoked column, in one transaction. |
| Remove | Runs `removeStaffUsing()`, or by default sets the revoked column. Their open session ends on the next request. |
| Delete | Only with `SSO_STAFF_DELETE=true`. Runs `deleteStaffUsing()`, or by default soft deletes a model that uses `SoftDeletes`, and refuses otherwise. |
| Name or email changed | Updates them, as a sign-in does. |
| Status | Answers each person's level from `staffLevelUsing()`, also for staff not linked yet (found by WorkOS ID or verified email, without linking). |

Good to know:

- Make the app's staff pages read-only: no adding, editing or deleting staff in the app, since the portal does that and copies their name and email. The app keeps managing its customers.
- The default remove needs a nullable timestamp column, `access_revoked_at` (or `SSO_REVOKED_COLUMN`), which the package migration doesn't add. Sign-in is refused while it's set.
- Refuse anything with `SsoException::refuse('reason')`. The portal shows the reason.
- Email links need `email_verified_at` set, also at first sign-in with sync on. Staff-only apps can skip this with `SSO_LINK_UNVERIFIED_EMAILS=true`.
- An email that only matches a deleted account is refused with `code: deleted_account`. The portal can resend with `restore_deleted: true` to bring that account back on purpose.
- If you use `Sso::resolveUsersUsing()`, call `Accounts::resolve()` inside it, so the "access removed" refusal still applies.
- Replay protection uses the app's cache, so production needs a shared, atomic store such as Redis or the database.
- Let `POST /auth/sso/staff` through any WAF or bot protection, like the back-channel URL.

## Filament

Panels don't use the `web` group, so add the middleware to each panel:

```php
use Webimpian\Sso\Http\Middleware\KeepSsoSession;

return $panel
    ->login(null)
    ->middleware([
        // ...the panel's other middleware
        KeepSsoSession::class,
    ]);
```

Guests go to the portal through the `login` route, and the user menu signs out through it on Filament 3, 4 and 5.

## Moving off an app's own WorkOS login

| Today | With the package |
|---|---|
| Login page, email code, Google and MFA | The portal's sign-in |
| Match by email, then save `workos_id` | `SSO_WORKOS_ID_COLUMN=workos_id` |
| Only some roles may get an account | The app's access in the portal, with `SSO_PROVISIONING=auto` |
| Extra fields on a new account | `Sso::createUsersUsing()` |
| Suspended check, last sign-in, avatar | `Sso::resolveUsersUsing()` |
| `workos_session_id` in the session | `Sso::check()` |
| Creating WorkOS users | `Sso::addStaff()` |
| Removing the WorkOS membership | `Sso::removeStaff()` for this app; removing someone from the company is a portal admin's job |
| WorkOS sync, Resync and `RevokeWorkosSessionOnLogout` | Remove them |

## Testing

`Sso::fake()` signs people in, and answers `addStaff()` and `removeStaff()`, without calling the portal:

```php
Sso::fake(['email' => $user->email, 'roles' => ['admin']]);

$this->followingRedirects()->get('/dashboard')->assertOk();
```

After `Sso::fake()`, staff notices take their claims as JSON in place of a signed token:

```php
$this->post('/auth/sso/staff', ['staff_token' => json_encode(['action' => 'levels'])]);
```

## Configuration

| Setting | Default |
|---|---|
| `SSO_ISSUER` | required, the portal's URL |
| `SSO_REDIRECT_URI` | `/auth/sso/callback` on this app |
| `SSO_SCOPES` | `openid profile email` |
| `SSO_PROVISIONING` | `existing-only` |
| `SSO_DEFAULT_ROLE` | none |
| `SSO_ROLE_MAP` | none |
| `SSO_SYNC_PROFILE` | `true`; `false` keeps the app's own name and email |
| `SSO_WORKOS_ID_COLUMN` | none |
| `SSO_LINK_UNVERIFIED_EMAILS` | `false`; `true` links accounts without a verified email (staff-only apps) |
| `SSO_POST_LOGOUT_REDIRECT_URI` | the home page; must be a sign-out URI on the app in the portal |
| `SSO_HOME` | `/`, where staff land when no page was asked for |
| `SSO_GUARD` | the default guard |
| `SSO_STAFF_SYNC` | `false`; `true` accepts staff notices from the portal |
| `SSO_STAFF_DELETE` | `false`; `true` lets the portal delete accounts |
| `SSO_REVOKED_COLUMN` | `access_revoked_at` |

`php artisan vendor:publish --tag=sso-config` publishes the config, and `--tag=sso-views` the package's two pages.

## License

MIT. See [LICENSE.md](LICENSE.md).
