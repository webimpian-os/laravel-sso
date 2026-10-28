<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @yield('head')
    <title>@yield('title')</title>
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        body { margin: 0; min-height: 100vh; box-sizing: border-box; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; padding: 24px 16px; background: #f3f5ef; color: #12210d; font: 14px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { box-sizing: border-box; width: 100%; max-width: 380px; padding: 22px; display: flex; flex-direction: column; gap: 14px; background: #fff; border: 1px solid #e3e8db; border-radius: 14px; box-shadow: 0 10px 28px rgba(18, 33, 13, .07); }
        header { display: flex; align-items: center; gap: 12px; }
        .badge { flex: none; display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; background: #eaf4d8; color: #4f8000; }
        .app { font-size: 12px; font-weight: 600; color: #4f8000; }
        h1 { margin: 0; font-size: 18px; font-weight: 650; line-height: 1.3; }
        p { margin: 0; color: #52525b; }
        .button { display: flex; align-items: center; justify-content: center; width: 100%; height: 40px; box-sizing: border-box; border: 0; border-radius: 9px; background: #4f8000; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .button:hover { background: #3e6600; }
        .button.secondary { border: 1px solid #d9e1cf; background: #fff; color: #12210d; }
        .button.secondary:hover { background: #f3f5ef; }
        .brand { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #5f6f4d; }
        .spin { animation: spin .9s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (pointer: coarse) { .button { height: 44px; } }
        @media (prefers-reduced-motion: reduce) { .spin { animation: none; } }
        @media (prefers-color-scheme: dark) {
            body { background: #0e1509; color: #e8f0de; }
            main { background: #161f10; border-color: #263319; box-shadow: none; }
            .badge { background: rgba(163, 230, 53, .12); color: #a3e635; }
            .app { color: #a3e635; }
            p { color: #a8b39b; }
            .button.secondary { border-color: #33422a; background: transparent; color: #e8f0de; }
            .button.secondary:hover { background: #1d2915; }
            .brand { color: #8c9a7c; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <span class="badge">@yield('icon')</span>
        <div>
            <p class="app">{{ config('app.name') }}</p>
            <h1>@yield('heading')</h1>
        </div>
    </header>
    @yield('body')
</main>
<p class="brand">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6z"/></svg>
    Web Impian SSO
</p>
@yield('scripts')
</body>
</html>
