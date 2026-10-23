<?php

return [
    // Required: the SSO portal's URL, e.g. https://sso.example.com.
    'issuer' => env('SSO_ISSUER'),

    'client_id' => env('SSO_CLIENT_ID'),

    'client_secret' => env('SSO_CLIENT_SECRET'),

    // Must match the app's callback URL in the portal exactly. Defaults to /auth/sso/callback.
    'redirect_uri' => env('SSO_REDIRECT_URI'),

    // "staff" is added when a role map or WorkOS id column is set; the portal must allow it.
    'scopes' => env('SSO_SCOPES', 'openid profile email'),

    // existing-only, auto or pending-approval: what a first sign-in without an account gets.
    'provisioning' => env('SSO_PROVISIONING', 'existing-only'),

    'default_role' => env('SSO_DEFAULT_ROLE'),

    // Portal role to app role, e.g. "admin:admin,member:viewer". Applied on every sign-in.
    'role_map' => env('SSO_ROLE_MAP'),

    // Copies the portal's name and email onto the account at every sign-in.
    'sync_profile' => (bool) env('SSO_SYNC_PROFILE', true),

    // The users column holding WorkOS user ids, for apps moving off their own WorkOS login.
    'workos_id_column' => env('SSO_WORKOS_ID_COLUMN'),

    // Must be one of the app's sign-out URIs in the portal. Defaults to the home page.
    'post_logout_redirect_uri' => env('SSO_POST_LOGOUT_REDIRECT_URI'),

    'home' => env('SSO_HOME', '/'),

    'guard' => env('SSO_GUARD'),
];
