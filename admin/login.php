<?php
if (!defined('ABSPATH')) {
    define('RK_FRONTEND_APP', true);
    define('WP_USE_THEMES', false);
    require_once(__DIR__ . '/../wp/wp-load.php');
}

if (is_user_logged_in() && current_user_can('administrator')) {
    wp_safe_redirect(add_query_arg('page', 'dashboard', trailingslashit(site_url('/admin/'))));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rk_admin_login'])) {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'rk_admin_login_nonce')) {
        $error = 'Security check failed. Please try again.';
    } else {
        $creds = [
            'user_login' => isset($_POST['log']) ? sanitize_text_field(wp_unslash($_POST['log'])) : '',
            'user_password' => isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '',
            'remember' => isset($_POST['rememberme']),
        ];

        $user = wp_signon($creds, is_ssl());
        if (is_wp_error($user)) {
            $error = $user->get_error_message();
        } elseif (!user_can($user->ID, 'administrator')) {
            wp_logout();
            $error = 'Access denied. Administrator privileges required.';
        } else {
            wp_safe_redirect(add_query_arg('page', 'dashboard', trailingslashit(site_url('/admin/'))));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - RaffleKings</title>
    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <style>
        .glass-panel { background: rgba(15, 23, 42, 0.78); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border: 1px solid rgba(255, 255, 255, 0.12); }
        .animated-bg { background: radial-gradient(circle at top left, rgba(79, 70, 229, 0.42), transparent 28rem), radial-gradient(circle at bottom right, rgba(14, 165, 233, 0.26), transparent 24rem), linear-gradient(135deg, #020617, #111827 45%, #1e1b4b); }
        .orb { filter: blur(1px); animation: float 9s ease-in-out infinite; }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-18px); } }
    </style>
</head>
<body class="animated-bg min-h-screen w-full flex items-center justify-center p-4 text-slate-100">
    <div class="pointer-events-none fixed inset-0 overflow-hidden">
        <div class="orb absolute left-10 top-16 h-32 w-32 rounded-full bg-indigo-500/20"></div>
        <div class="orb absolute bottom-16 right-16 h-44 w-44 rounded-full bg-cyan-400/10" style="animation-delay: -3s"></div>
    </div>

    <div class="glass-panel relative w-full max-w-md rounded-3xl p-8 shadow-2xl shadow-black/40">
        <div class="mb-8 text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-500/20 text-indigo-200 ring-1 ring-indigo-400/30">
                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <h1 class="text-3xl font-black tracking-[0.3em] text-white">RAFFLEKINGS</h1>
            <p class="mt-2 text-sm text-slate-400">Secure Operations Platform</p>
        </div>

        <?php if (isset($_GET['logged_out'])): ?>
            <div class="mb-6 rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">You have been signed out securely.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 flex items-start rounded-2xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <svg class="mr-2 mt-0.5 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span><?php echo wp_kses_post($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="" class="space-y-6" id="loginForm">
            <?php wp_nonce_field('rk_admin_login_nonce', '_wpnonce'); ?>
            <input type="hidden" name="rk_admin_login" value="1">

            <div>
                <label for="log" class="mb-2 block text-sm font-semibold text-slate-300">Username or Email</label>
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <input type="text" name="log" id="log" required class="block w-full rounded-xl border border-slate-700 bg-slate-950/60 py-3 pl-10 pr-3 text-white placeholder-slate-500 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30" placeholder="admin">
                </div>
            </div>

            <div>
                <label for="pwd" class="mb-2 block text-sm font-semibold text-slate-300">Password</label>
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <input type="password" name="pwd" id="pwd" required class="block w-full rounded-xl border border-slate-700 bg-slate-950/60 py-3 pl-10 pr-12 text-white placeholder-slate-500 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30" placeholder="••••••••">
                    <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-500 transition hover:text-slate-200" aria-label="Toggle password visibility">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" id="eyeIcon"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </button>
                </div>
            </div>

            <label class="flex items-center gap-3 text-sm text-slate-300">
                <input id="rememberme" name="rememberme" type="checkbox" class="h-4 w-4 rounded border-slate-700 bg-slate-950 text-indigo-500 focus:ring-indigo-500">
                Remember me
            </label>

            <button type="submit" id="submitBtn" class="flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-indigo-950/40 transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2 focus:ring-offset-slate-950">
                <span>Sign in to Dashboard</span>
                <svg id="btnSpinner" class="ml-2 hidden h-5 w-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            </button>
        </form>
    </div>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('pwd');
        const eyeIcon = document.getElementById('eyeIcon');

        togglePassword.addEventListener('click', () => {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            eyeIcon.innerHTML = type === 'text'
                ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />'
                : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
        });

        document.getElementById('loginForm').addEventListener('submit', () => {
            const btn = document.getElementById('submitBtn');
            const spinner = document.getElementById('btnSpinner');
            btn.querySelector('span').textContent = 'Authenticating...';
            btn.classList.add('cursor-not-allowed', 'opacity-75');
            spinner.classList.remove('hidden');
        });
    </script>
</body>
</html>
