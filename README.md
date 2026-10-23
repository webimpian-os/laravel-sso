# Laravel SSO

Sign staff in to a Laravel app through the Web Impian SSO portal at `internal.webimpian.com`. The portal handles the sign-in, two-step verification and who may open the app. The app keeps its own users and roles.

## Requirements

- PHP 8.1 or higher
- Laravel 10, 11, 12 or 13
- The app registered in the portal, with its client ID and secret

## Installation

```bash
composer require webimpian/laravel-sso
php artisan migrate
```

The migration adds `sso_sub` (the portal's ID for the person) and `sso_pending` to the users table.

Copy the `.env` block from the app's Connection tab in the portal:

```env
SSO_ISSUER=https://internal.webimpian.com
SSO_CLIENT_ID=your-client-id
SSO_CLIENT_SECRET=your-client-secret
```

Send guests to the portal instead of the old login page, and sign out through it:

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

## Routes

Register these URLs on the app in the portal. The company apps are already registered with them.

| Route | URL | Use |
|---|---|---|
| `sso.login` | `GET /auth/sso/login` | Starts a sign-in. Also the app's **sign-in URL**, which the portal home page opens. |
| `sso.callback` | `GET /auth/sso/callback` | The **callback URL**. |
| `sso.logout` | `POST /auth/sso/logout` | Signs out of the app and the portal. |
| `sso.backchannel` | `POST /auth/sso/backchannel-logout` | The **back-channel logout URL**. The portal calls it server to server, so let it through Cloudflare. |

## First sign-in

Who may open the app is set in the portal: all staff, listed people, a WorkOS role or a group.

People are found by `sub`, never by email. On their first sign-in, the package links an existing account that isn't linked yet: by WorkOS user ID when `SSO_WORKOS_ID_COLUMN` is set, otherwise by email. With no account to link, `SSO_PROVISIONING` decides:

| Mode | What happens |
|---|---|
| `existing-only` (default) | "Ask an admin of this app to add you" |
| `auto` | Creates the account, with `SSO_DEFAULT_ROLE` if set |
| `pending-approval` | Creates the account but holds it until `php artisan sso:approve someone@webimpian.com` |

## Roles

Roles stay in the app. To take them from the portal's WorkOS roles on every sign-in, map them:

```env
SSO_ROLE_MAP=admin:admin,member:viewer
SSO_DEFAULT_ROLE=viewer
```

People with no mapped role get the default role, or none. Models with `syncRoles()` (Spatie Permission) work as they are; otherwise tell the package how to give roles:

```php
Sso::syncRolesUsing(fn (User $user, array $roles) => $user->update(['role' => $roles[0] ?? 'viewer']));
```

A role map, or `SSO_WORKOS_ID_COLUMN`, adds the `staff` scope, which an admin must allow on the app's Access tab.

## Staying signed in

The idle timeout and access check interval are set per app in the portal. The package applies them to every route in the `web` group.

| When | What happens |
|---|---|
| Idle past the app's timeout | The app session ends, and coming back needs a full sign-in. |
| Access check interval passes | The next page load checks with the portal silently. |
| Access removed in the portal | The app session ends: "You no longer have access to this app". |
| Portal session ended | The person signs in again and comes back to the same page. |
| Portal unreachable | The session stays; the next interval tries again. |
| Signed out or revoked in the portal | The back-channel logout ends that session on its next request. |

Checks run only on full page loads, never on form posts or background requests, so nothing typed is lost. Inertia visits get a full page visit; Livewire and other background requests get a 419 once the session has ended. There is no "remember me": the portal's session already brings staff straight back.

## Filament

Panels don't use the `web` group, so add the middleware to each panel and send guests to the portal:

```php
use Webimpian\Sso\Http\Middleware\KeepSsoSession;

return $panel
    ->login(null)
    ->middleware([
        // ...StartSession and the rest of the panel's middleware
        KeepSsoSession::class,
    ]);
```

With the `login` route above, guests go to the portal. Signing out from the user menu goes through the portal by itself on Filament 3, 4 and 5.

## Moving an app off its own WorkOS login

Monitor, Onboard and Billing each run their own WorkOS sign-in today. The package keeps the same flow:

| Today | With the package |
|---|---|
| Login page, email code, Google and MFA | The portal's sign-in. Point the `login` route at `/auth/sso/login`. |
| Match by email, then save `workos_id` | `SSO_WORKOS_ID_COLUMN=workos_id` links by WorkOS ID first, then email. |
| Only the admin role may get an account | Set the app's access to that role in the portal, with `SSO_PROVISIONING=auto`. |
| Fields set on a new account (`user_type`, `user_role`) | `Sso::createUsersUsing()` |
| Revoked check, `last_signed_in_at`, avatar | `Sso::resolveUsersUsing()` |
| `workos_session_id` in the session | `Sso::check()` |
| `RevokeWorkosSessionOnLogout` | Remove it. The portal ends the WorkOS session. |
| The "add user" page creates them in WorkOS | `Sso::addStaff()`, below |

The staff sync, webhooks and admin pages can stay. For example, in `AppServiceProvider::boot()`:

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

    if ($user->access_revoked_at !== null) {
        throw SsoException::refuse('Your access to this app was removed. Ask your admin if that is unexpected.');
    }

    $user->forceFill(['last_signed_in_at' => now(), 'avatar_url' => $claims['picture'] ?? $user->avatar_url])->save();

    return $user;
});
```

The claims are `sub`, `sid`, `name`, `email`, `picture`, and with the `staff` scope `roles` and `workos_user_id`.

## Adding staff

From any of its own pages, an app can add someone to WorkOS and the portal, and give them access to the app, in one call. First turn on **Can add staff** on the app's Access tab in the portal.

```php
$staff = Sso::addStaff('aina@webimpian.com', 'Aina', 'Rahman');

User::create(['name' => $staff['name'], 'email' => $staff['email'], 'sso_sub' => $staff['sub']]);
```

- New people are created in WorkOS and the portal; people already on staff are found, not duplicated. Nobody is emailed, so tell them to sign in.
- An app with an access list adds them to it, and an app for all staff needs nothing. An app that gives access by WorkOS role or group gets `access: false`, because those stay with portal admins.
- A refusal throws `SsoException` with the portal's reason and status: 409 for someone whose access was revoked, 422 for an address outside the company domains, 503 when WorkOS is down.
- Saving `sub` straight away means their first sign-in matches without any email linking.

## Local sign-in

Sessions that didn't come from the portal, such as a break-glass admin login, are left alone, and signing out sends them to the home page.

## Testing

`Sso::fake()` signs people in, and `Sso::addStaff()` answers, without calling the portal:

```php
Sso::fake(['email' => $user->email, 'roles' => ['admin']]);

$this->followingRedirects()->get('/dashboard')->assertOk();
```

## Settings

| Setting | Default |
|---|---|
| `SSO_ISSUER` | `https://internal.webimpian.com` |
| `SSO_REDIRECT_URI` | `/auth/sso/callback` on this app |
| `SSO_SCOPES` | `openid profile email` |
| `SSO_PROVISIONING` | `existing-only` |
| `SSO_DEFAULT_ROLE` | none |
| `SSO_ROLE_MAP` | none |
| `SSO_WORKOS_ID_COLUMN` | none |
| `SSO_POST_LOGOUT_REDIRECT_URI` | the home page; must be a sign-out URI on the app in the portal |
| `SSO_HOME` | `/`, where staff land when no page was asked for |
| `SSO_GUARD` | the default guard |

`php artisan vendor:publish --tag=sso-config` publishes the config, and `--tag=sso-views` the two pages the package shows.

## License

MIT. See [LICENSE.md](LICENSE.md).
