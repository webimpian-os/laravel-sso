@php($idleTimeout = session('sso.idle_timeout'))
@if (is_int($idleTimeout) && \Webimpian\Sso\Sso::guard()->check())
<script data-navigate-once @if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
(() => {
    if (window.ssoKeepAlive) return;
    window.ssoKeepAlive = true;

    const idle = {{ $idleTimeout }} * 1000;
    const url = @json(route('sso.active', absolute: false));
    const token = @json(csrf_token());
    const pingEvery = Math.min(300000, idle / 3);
    const warnFor = Math.min(120000, idle / 2);
    let activeAt = Date.now();
    let touched = false;
    let leaving = false;
    let banner = null;
    let countdown = null;

    const hide = () => {
        if (banner) banner.remove();
        banner = null;
    };

    const ping = () => {
        touched = false;
        const at = Date.now();

        fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => {
                if (response.status === 419) return location.reload();
                if (!response.ok) return;
                activeAt = Math.max(activeAt, at);
                hide();
                try {
                    localStorage.setItem('sso.active_at', String(at));
                } catch (error) {}
            })
            .catch(() => {});
    };

    const show = (left) => {
        if (!banner || !banner.isConnected) {
            banner = document.createElement('div');
            banner.style.cssText = 'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(18,33,13,.55);font:400 14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif';
            banner.innerHTML = '<div role="alertdialog" aria-modal="true" aria-labelledby="sso-idle-title" style="display:flex;align-items:center;gap:14px;width:100%;max-width:420px;box-sizing:border-box;padding:14px 14px 14px 16px;border-radius:14px;background:#fff;color:#12210d;box-shadow:0 20px 50px rgba(0,0,0,.3)">'
                + '<div style="flex:none;display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;background:#eaf4d8"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4f8000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>'
                + '<div style="flex:1;min-width:0"><p id="sso-idle-title" style="margin:0;font-size:15px;font-weight:600">Still there?</p>'
                + '<p style="margin:2px 0 0;font-size:13px;color:#52525b">Signing out in <strong data-left style="color:#4f8000;font-variant-numeric:tabular-nums"></strong></p></div>'
                + '<button type="button" style="flex:none;height:36px;padding:0 14px;border:0;border-radius:9px;background:#4f8000;color:#fff;font:inherit;font-weight:600;cursor:pointer">Stay signed in</button>'
                + '</div>';
            countdown = banner.querySelector('[data-left]');
            banner.querySelector('button').addEventListener('click', ping);
            document.body.append(banner);
        }

        const seconds = Math.ceil(left / 1000);
        countdown.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    };

    ['keydown', 'pointerdown', 'pointermove', 'wheel', 'touchstart', 'scroll'].forEach((type) => addEventListener(type, () => (touched = true), { capture: true, passive: true }));

    addEventListener('storage', (event) => event.key === 'sso.active_at' && (activeAt = Math.max(activeAt, Number(event.newValue) || 0)));

    setInterval(() => {
        if (leaving) return;
        const now = Date.now();
        if (touched && now - activeAt >= pingEvery) ping();

        const left = activeAt + idle - now;
        if (left > warnFor) return hide();
        if (left > 0) return show(left);

        leaving = true;
        location.reload();
    }, 1000);
})();
</script>
@endif
