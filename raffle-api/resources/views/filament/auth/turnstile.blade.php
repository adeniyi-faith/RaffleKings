{{-- Cloudflare Turnstile on the admin sign-in (Settings → Security → "Check on log-in"). --}}
<div
    wire:ignore
    x-data
    x-init="
        const render = () => window.turnstile.render($refs.box, {
            sitekey: @js($siteKey),
            callback: (token) => $wire.set('data.turnstile_token', token),
            'expired-callback': () => $wire.set('data.turnstile_token', null),
        });
        if (window.turnstile) { render(); } else {
            const s = document.createElement('script');
            s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
            s.async = true;
            s.onload = render;
            document.head.appendChild(s);
        }
    "
>
    <div x-ref="box"></div>
</div>
