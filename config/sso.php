<?php

return [
    // Required: the SSO portal's URL.
    'issuer' => env('SSO_ISSUER'),

    'client_id' => env('SSO_CLIENT_ID'),

    'client_secret' => env('SSO_CLIENT_SECRET'),

    // Must match the callback URL in the portal; defaults to /auth/sso/callback.
    'redirect_uri' => env('SSO_REDIRECT_URI'),

    // "staff" is added when a role map or WorkOS id column is set, and the portal must allow it.
    'scopes' => env('SSO_SCOPES', 'openid profile email'),

    // existing-only, auto or pending-approval.
    'provisioning' => env('SSO_PROVISIONING', 'existing-only'),

    'default_role' => env('SSO_DEFAULT_ROLE'),

    // Portal role to app role, e.g. "admin:admin,member:viewer", reapplied at every sign-in.
    'role_map' => env('SSO_ROLE_MAP'),

    'sync_profile' => (bool) env('SSO_SYNC_PROFILE', true),

    // For apps moving off their own WorkOS login.
    'workos_id_column' => env('SSO_WORKOS_ID_COLUMN'),

    // Must be one of the app's sign-out URLs in the portal; defaults to the home page.
    'post_logout_redirect_uri' => env('SSO_POST_LOGOUT_REDIRECT_URI'),

    'home' => env('SSO_HOME', '/'),

    'guard' => env('SSO_GUARD'),

    'staff' => [
        'enabled' => (bool) env('SSO_STAFF_SYNC', false),
        'deletes' => (bool) env('SSO_STAFF_DELETE', false),
        // The app adds this column itself; the package migration doesn't.
        'revoked_column' => env('SSO_REVOKED_COLUMN', 'access_revoked_at'),
    ],
];
