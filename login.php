<?php
ob_start(); // Buffer ALL output from the start
// --- DIRECT LOGIN PROCESSING (No router needed!) ---
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

// If this is a form submission from our JavaScript, handle it here and stop loading the HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    ob_clean(); // Discard any buffered warnings/notices from wp-load
    header('Content-Type: application/json');

    $username = sanitize_user($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
        exit;
    }

    $user = wp_signon([
        'user_login'    => $username,
        'user_password' => $password,
        'remember'      => true
    ], is_ssl());

    if (is_wp_error($user)) {
        echo json_encode(['success' => false, 'message' => strip_tags($user->get_error_message())]);
    } elseif (function_exists('rk_check_user_status') && is_wp_error($status = rk_check_user_status($user->ID, 'general'))) {
        wp_logout();
        echo json_encode(['success' => false, 'message' => $status->get_error_message(), 'code' => $status->get_error_code()]);
    } else {
        echo json_encode([
            'success' => true,
            'redirect' => function_exists('rk_auth_safe_return_url') ? rk_auth_safe_return_url('index.php') : 'index.php',
            'auth' => function_exists('rk_auth_cookie_params') ? rk_auth_cookie_params() : ['logged_in' => true, 'user_id' => $user->ID],
            'user' => ['email' => $user->user_email, 'name' => $user->display_name]
        ]);
    }
    exit; // Stop HTML rendering, just return the JSON response back to JS
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Login - Resume Mission</title>

    <!-- PWA & Mobile Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RaffleKings">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'app-primary': '#2563eb',
                        'app-secondary': '#1e40af',
                        'app-bg': '#f8fafc',
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Centralized config (Can still keep this for other non-auth settings) -->
    <script src="config.js?v=<?php echo time(); ?>"></script>

    <style>
        /* Base Resets */
        * { -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Inter', sans-serif; background-color: #F3F4F6; }
        input { font-size: 16px !important; }

        /* Mobile Safe Areas */
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-[100dvh] px-4 safe-top safe-bottom">

    <div class="w-full max-w-sm">

        <!-- Header -->
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-white shadow-lg rounded-2xl flex items-center justify-center mx-auto mb-6 transform rotate-3">
                <i data-lucide="zap" class="w-8 h-8 text-app-primary fill-current"></i>
            </div>
            <h1 class="text-2xl font-extrabold text-gray-900">Resume Mission</h1>
            <p class="text-sm text-gray-500 mt-2">Welcome back, winner.</p>
        </div>

        <!-- Login Form -->
        <div class="bg-white rounded-3xl shadow-xl shadow-gray-200/50 p-8 border border-white">
            <form id="login-form" onsubmit="handleLogin(event)" class="space-y-5">

                <!-- Error Message Container -->
                <div id="error-msg" class="hidden bg-red-50 text-red-600 p-3 rounded-lg text-xs font-bold text-center border border-red-100 flex items-center justify-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4"></i>
                    <span id="error-text"></span>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Email Address</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i data-lucide="mail" class="h-5 w-5 text-gray-300"></i>
                        </div>
                        <input type="email" name="username" id="input-username" placeholder="you@example.com" class="w-full bg-gray-50 border border-gray-100 text-gray-900 rounded-xl pl-11 pr-4 py-3.5 outline-none focus:bg-white focus:ring-2 focus:ring-app-primary/20 focus:border-app-primary/50 transition-all font-medium placeholder-gray-400" required>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i data-lucide="lock" class="h-5 w-5 text-gray-300"></i>
                        </div>
                        <input type="password" id="login-password" name="password" placeholder="••••••••" class="w-full bg-gray-50 border border-gray-100 text-gray-900 rounded-xl pl-11 pr-12 py-3.5 outline-none focus:bg-white focus:ring-2 focus:ring-app-primary/20 focus:border-app-primary/50 transition-all font-medium placeholder-gray-400" required>

                        <!-- Toggle Button -->
                        <button type="button" onclick="togglePassword('login-password', 'eye-icon-login')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none">
                            <i data-lucide="eye" id="eye-icon-login" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <div class="flex justify-end mt-2">
                        <a href="forgot-password.php" class="text-xs font-bold text-app-primary hover:text-app-secondary">Forgot Password?</a>
                    </div>
                </div>

                <button type="submit" id="login-btn" class="w-full bg-gray-900 text-white py-4 rounded-xl font-bold shadow-lg shadow-gray-900/20 active:scale-[0.98] transition-transform flex items-center justify-center gap-2 mt-4">
                    Login Now <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>

            </form>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8">
            <p class="text-sm text-gray-500">
                Don't have an identity yet?
                <!-- Dynamic Register Link -->
                <a href="register.php" id="register-link" class="font-bold text-app-primary hover:underline">Create One</a>
            </p>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // Check for Redirect param on load
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);

            // Handle Redirect Param
            const redirect = urlParams.get('redirect');
            if (redirect === 'cart') {
                const link = document.getElementById('register-link');
                link.href = 'register.php?redirect=cart';
            }

            // Handle Pre-filled Email
            const filledEmail = urlParams.get('filled_email');
            if (filledEmail) {
                const emailInput = document.getElementById('input-username');
                if (emailInput) {
                    emailInput.value = decodeURIComponent(filledEmail);
                }
            }
        });

        async function handleLogin(e) {
            e.preventDefault();

            const btn = document.getElementById('login-btn');
            const errorDiv = document.getElementById('error-msg');
            const errorText = document.getElementById('error-text');
            const originalText = btn.innerHTML;

            // Reset UI
            errorDiv.classList.add('hidden');
            btn.disabled = true;
            btn.innerHTML = '<span class="animate-spin"><i data-lucide="loader-2" class="w-5 h-5"></i></span>';
            lucide.createIcons();

            // 1. Get the form data natively
            const formData = new FormData(document.getElementById('login-form'));

            // 2. Append our action
            formData.append('action', 'login');

            try {
                // 3. SEND DATA DIRECTLY TO THIS EXACT PAGE
                const response = await fetch(window.location.href.split('?')[0], {
                    method: 'POST',
                    body: formData
                });

                // Rate Limit Handling
                if (response.status === 429) {
                    throw new Error("Too many login attempts. Please wait 1 minute.");
                }

                const result = await response.json();

                    // WordPress auth cookies are set server-side; check the success flag.
                if (response.ok && result.success) {

                    // Session Cleanup
                    const keysToRemove = ['user_display_name', 'user_avatar_url', 'walletBalance', 'earningsBalance'];
                    keysToRemove.forEach(k => localStorage.removeItem(k));

                    // Store User Info locally for the UI to use if needed
                    if (result.user) {
                        localStorage.setItem('user_email', result.user.email);
                        localStorage.setItem('user_display_name', result.user.name);
                    }

                    // --- REDIRECT LOGIC ---
                    const urlParams = new URLSearchParams(window.location.search);
                    const redirect = urlParams.get('redirect');

                    if (redirect === 'cart') {
                        // Check if specific checkout data exists
                        if(localStorage.getItem('pendingCheckout')) {
                            try {
                                const item = JSON.parse(localStorage.getItem('pendingCheckout'));
                                // Validate Item Age (Expire after 1 hour)
                                const now = new Date().getTime();
                                if (item.timestamp && (now - item.timestamp < 3600000)) {
                                    const amount = item.amount || item.price;
                                    const tickets = item.tickets || item.qty;
                                    const numbers = Array.isArray(item.numbers) ? item.numbers.join(',') : item.numbers;
                                    const rId = item.raffle_id || item.raffleId;

                                    const url = `checkout.php?amount=${amount}&tickets=${tickets}&numbers=${numbers}&raffle_id=${rId}`;
                                    window.location.href = url;
                                } else {
                                    localStorage.removeItem('pendingCheckout');
                                    window.location.href = 'cart.php';
                                }
                            } catch(e) {
                                window.location.href = 'cart.php';
                            }
                        } else {
                            window.location.href = 'cart.php';
                        }
                    } else {
                        window.location.href = result.redirect || 'index.php';
                    }

                } else {
                    throw new Error(result.message || 'Invalid email or password.');
                }

            } catch (error) {
                // Sanitize Error Message
                const safeMsg = error.message.replace(/<[^>]*>?/gm, '');
                errorText.innerText = safeMsg;
                errorDiv.classList.remove('hidden');

                btn.disabled = false;
                btn.innerHTML = originalText;
                lucide.createIcons();
            }
        }

        // PASSWORD TOGGLE
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === "password") {
                input.type = "text";
                icon.setAttribute('data-lucide', 'eye-off');
            } else {
                input.type = "password";
                icon.setAttribute('data-lucide', 'eye');
            }
            lucide.createIcons();
        }
    </script>
</body>
</html>
