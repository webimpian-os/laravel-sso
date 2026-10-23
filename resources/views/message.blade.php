<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f5f6f8; color: #1f2933; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { box-sizing: border-box; width: calc(100% - 32px); max-width: 26rem; padding: 2rem; background: #fff; border: 1px solid #e2e5e9; border-radius: 12px; }
        h1 { margin: 0 0 .5rem; font-size: 1.125rem; }
        p { margin: 0 0 1.25rem; color: #52606d; }
        a { color: #1d4ed8; font-weight: 600; text-decoration: none; }
        @media (prefers-color-scheme: dark) {
            body { background: #111418; color: #e4e7eb; }
            main { background: #1b1f24; border-color: #2c3239; }
            p { color: #9aa5b1; }
            a { color: #8ab4f8; }
        }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>
    <p>{{ $message }}</p>
    <a href="{{ route('sso.login') }}">Sign in again</a>
</main>
</body>
</html>
