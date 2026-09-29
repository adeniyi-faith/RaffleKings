{{--
    Error page for the admin (/admin/...). Every style is written right
    here: the admin swaps pages in place, and the customer error page's
    stylesheet never loaded inside it, which left a blank white "Try
    again" button. Used by bootstrap/app.php when debug mode is off.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $status }} · {{ $title }}</title>
    <style>
        .rk-err { box-sizing: border-box; min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; padding: 20px; background: #f3f4f6; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111827; text-align: center; }
        .rk-err * { box-sizing: border-box; }
        .rk-err-card { width: 100%; max-width: 380px; background: #fff; border-radius: 24px; padding: 32px 24px; box-shadow: 0 20px 40px rgb(30 58 138 / .08); }
        .rk-err-emoji { width: 88px; height: 88px; margin: 0 auto 20px; border-radius: 50%; background: #eff6ff; display: flex; align-items: center; justify-content: center; font-size: 44px; }
        .rk-err-code { margin: 0; font-size: 56px; line-height: 1; font-weight: 800; letter-spacing: -.04em; }
        .rk-err-title { margin: 8px 0 12px; font-size: 18px; font-weight: 700; }
        .rk-err-text { margin: 0 0 28px; font-size: 14px; line-height: 1.6; color: #6b7280; }
        .rk-err-btn { display: flex; width: 100%; align-items: center; justify-content: center; gap: 8px; padding: 14px; border-radius: 12px; font: inherit; font-size: 15px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .rk-err-primary { border: 0; background: #d97706; color: #fff; box-shadow: 0 8px 20px rgb(217 119 6 / .3); }
        .rk-err-secondary { margin-top: 12px; border: 1px solid #e5e7eb; background: #fff; color: #374151; }
        @media (prefers-color-scheme: dark) {
            .rk-err { background: #030712; color: #f9fafb; }
            .rk-err-card { background: #111827; box-shadow: none; border: 1px solid #1f2937; }
            .rk-err-emoji { background: rgb(30 58 138 / .25); }
            .rk-err-text { color: #9ca3af; }
            .rk-err-secondary { background: transparent; border-color: #374151; color: #e5e7eb; }
        }
    </style>
</head>
<body class="rk-err">
    <main class="rk-err-card">
        <div class="rk-err-emoji" aria-hidden="true">{{ $emoji }}</div>
        <h1 class="rk-err-code">{{ $status }}</h1>
        <h2 class="rk-err-title">{{ $title }}</h2>
        <p class="rk-err-text">{{ $message }}</p>
        @if (! empty($reference))
            <p class="rk-err-text">Error code: <strong>{{ $reference }}</strong> (search for it on System → Health or in the log)</p>
        @endif
        <button type="button" class="rk-err-btn rk-err-primary" onclick="window.location.reload()">↻ Try again</button>
        <a href="{{ url('/admin') }}" class="rk-err-btn rk-err-secondary">← Admin dashboard</a>
    </main>
</body>
</html>
