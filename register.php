<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Join RaffleKings - Registration</title>

    <!-- PWA & Mobile Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RaffleKings">

    <!-- PWA Manifest Link -->
    <link rel="manifest" href="manifest.json">

    <!-- Icons -->
    <link rel="apple-touch-icon" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png">
    <link rel="icon" type="image/png" sizes="32x32" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@32-px.png">
    <link rel="icon" type="image/png" sizes="16x16" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@16-px.png">
    <link rel="shortcut icon" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/App_Icon.png">

    <!-- Cloudflare Turnstile -->
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

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

    <!-- Config -->
    <script src="config.js?v=<?php echo time(); ?>"></script>
    <script src="watchdog.js"></script>
    <script src="analytics.js"></script>

    <style>
        * { -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Inter', sans-serif; background-color: #F3F4F6; }
        .slider-container { display: flex; width: 200%; transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1); }
        .slide { width: 50%; flex-shrink: 0; padding: 1rem; }
        input, select { font-size: 16px !important; }
        .safe-top { padding-top: env(safe-area-inset-top); }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 flex flex-col h-[100dvh]">

    <!-- Top Bar: Progress -->
    <!-- Added z-30 for better layering -->
    <div class="px-6 pt-6 pb-2 safe-top bg-gray-50 z-30 relative shadow-sm">
        <div class="flex justify-between items-end mb-2 max-w-md mx-auto">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider" id="step-label">Mission 1/2</span>
            <span class="text-xs font-bold text-app-primary" id="step-percent">50%</span>
        </div>
        <div class="h-2 bg-gray-200 rounded-full overflow-hidden max-w-md mx-auto">
            <div id="progress-bar" class="h-full bg-app-primary transition-all duration-500 ease-out" style="width: 50%"></div>
        </div>
    </div>

    <!-- Main Content -->
    <!-- Changed overflow-hidden to overflow-y-auto to prevent clipping on small screens -->
    <main class="flex-1 overflow-y-auto relative w-full max-w-md mx-auto no-scrollbar">

        <!-- Changed h-full to min-h-full to allow expansion -->
        <form id="reg-form" class="min-h-full flex flex-col">

            <!-- Hidden Referrer -->
            <input type="hidden" name="referrer" id="input-referrer" value="">

            <div class="slider-container h-full" id="slider">

                <!-- STEP 1: Identity -->
                <!-- Added py-10 for vertical safe space -->
                <div class="slide flex flex-col justify-center py-10">

                    <div class="text-center mb-6">
                        <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                            <i data-lucide="user-plus" class="w-6 h-6"></i>
                        </div>
                        <h1 class="text-xl font-extrabold text-gray-900 mb-1">Create Identity</h1>

                        <p id="ref-welcome" class="text-xs text-gray-500 hidden">
                            Invited by <span class="font-bold text-app-primary" id="ref-name">...</span>
                        </p>
                        <p id="default-welcome" class="text-xs text-gray-500">Join 12,000+ winners today.</p>
                    </div>

                    <!-- NEW: Locked Funds Card -->
                    <div class="bg-gray-900 rounded-2xl p-4 mb-6 relative overflow-hidden shadow-xl border border-gray-800 group">
                        <!-- Glow Effect -->
                        <div class="absolute -top-10 -right-10 w-32 h-32 bg-yellow-500/20 rounded-full blur-3xl group-hover:bg-yellow-500/30 transition-all duration-1000"></div>

                        <div class="flex items-center justify-between relative z-10">
                            <div class="flex items-center gap-3">
                                <div class="bg-gray-800 p-2.5 rounded-xl border border-gray-700 shadow-inner">
                                    <i data-lucide="lock" class="w-6 h-6 text-gray-400 group-hover:text-yellow-400 transition-colors duration-300"></i>
                                </div>
                                <div>
                                    <p class="text-[10px] uppercase font-bold text-gray-400 tracking-wider mb-0.5">Welcome Bonus</p>
                                    <h3 class="text-xl font-bold text-white tracking-tight flex items-center gap-2">
                                        ₦300.00 <span class="text-[10px] bg-gray-800 text-gray-300 px-1.5 py-0.5 rounded border border-gray-700">LOCKED</span>
                                    </h3>
                                </div>
                            </div>
                        </div>

                        <!-- CTA Strip -->
                        <div class="mt-3 pt-3 border-t border-gray-800 flex items-center justify-between">
                            <div class="flex items-center gap-1.5">
                                <i data-lucide="ticket" class="w-3 h-3 text-yellow-500"></i>
                                <p class="text-[10px] text-gray-300">Unlock & use for tickets instantly</p>
                            </div>
                            <span class="text-[10px] font-bold text-yellow-400 flex items-center gap-1 animate-pulse">
                                Register to Unlock <i data-lucide="arrow-down" class="w-3 h-3"></i>
                            </span>
                        </div>
                    </div>
                    <!-- End Locked Funds -->

                    <div class="space-y-4">
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wide ml-1">Username</label>
                            <input type="text" name="username" id="input-username" placeholder="Pick a winning name" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20 transition-all text-gray-800 font-medium shadow-sm" required>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wide ml-1">Email Address</label>
                            <input type="email" name="email" id="input-email" placeholder="Where do we send your prize alerts" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20 transition-all text-gray-800 font-medium shadow-sm" required>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-gray-500 uppercase tracking-wide ml-1">Password</label>
                            <div class="relative">
                                <input type="password" name="password" id="input-password" placeholder="••••••••" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20 transition-all text-gray-800 font-medium shadow-sm pr-12" required>
                                <button type="button" onclick="togglePassword('input-password', 'eye-icon-reg')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none">
                                    <i data-lucide="eye" id="eye-icon-reg" class="w-5 h-5"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 space-y-4">
                        <button type="button" onclick="goToStep(2)" class="w-full bg-gray-900 text-white py-4 rounded-xl font-bold shadow-lg shadow-gray-900/20 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
                            Next Mission <i data-lucide="arrow-right" class="w-4 h-4"></i>
                        </button>
                        <p class="text-center text-xs text-gray-400">
                            Already have an account? <a href="login.php" id="login-link" class="text-app-primary font-bold">Login</a>
                        </p>
                    </div>
                </div>

                <!-- STEP 2: The Manifesto -->
                <!-- Added py-10 for consistency -->
                <div class="slide flex flex-col justify-center py-10">
                    <div class="text-center mb-4">
                        <div class="w-16 h-16 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center mx-auto mb-4 animate-bounce">
                            <i data-lucide="scroll" class="w-8 h-8"></i>
                        </div>
                        <h1 class="text-2xl font-extrabold text-gray-900 mb-2">Community Pledge</h1>
                        <p class="text-sm text-gray-500">Please accept our terms to join.</p>
                    </div>

                    <div class="bg-white border-2 border-gray-100 rounded-2xl p-5 shadow-sm mb-4 relative overflow-hidden">
                        <div class="absolute top-0 left-0 w-1 h-full bg-app-primary"></div>
                        <h3 class="font-bold text-gray-900 text-sm uppercase tracking-wide mb-3 flex items-center gap-2"><i data-lucide="scroll" class="w-4 h-4 text-app-primary"></i> Our Promise</h3>
                        <ul class="space-y-4">
                            <li class="flex items-start gap-3 text-xs text-gray-600"><div class="w-5 h-5 rounded-full bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0 mt-0.5">1</div><span>We are a <strong>Community Support Fund</strong>, not a gambling or betting site.</span></li>
                            <li class="flex items-start gap-3 text-xs text-gray-600"><div class="w-5 h-5 rounded-full bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0 mt-0.5">2</div><span>We operate with <strong>100% Transparency</strong>. </span></li>
                            <li class="flex items-start gap-3 text-xs text-gray-600"><div class="w-5 h-5 rounded-full bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0 mt-0.5">3</div><span>Prizes are paid <strong>Directly</strong> to verified bank accounts.</span></li>
                        </ul>
                    </div>

                    <!-- Term Checkbox (Reduced Spacing) -->
                    <label class="flex items-center gap-3 bg-gray-50 p-4 rounded-xl border border-gray-200 cursor-pointer mb-4 transition-colors hover:bg-gray-100 active:scale-[0.98]">
                        <div class="relative flex items-center">
                            <input type="checkbox" id="pledge-check" class="peer h-6 w-6 cursor-pointer appearance-none rounded-lg border-2 border-gray-300 transition-all checked:border-app-primary checked:bg-app-primary">
                            <i data-lucide="check" class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-4 h-4 text-white opacity-0 peer-checked:opacity-100 pointer-events-none"></i>
                        </div>
                        <span class="text-sm font-bold text-gray-700">
                            I Agree & Accept Terms
                            <a href="/toc.php" target="_blank" class="text-app-primary text-xs underline ml-1 font-medium">(Read Full ToC)</a>
                        </span>
                    </label>

                    <!-- Turnstile Widget -->
                    <div class="mb-4 flex justify-center">
                        <div class="cf-turnstile" data-sitekey="0x4AAAAAACMsPBMFl2oCJQvS"></div>
                    </div>

                    <!-- Buttons Lifted -->
                    <div class="flex gap-3 mt-auto mb-6">
                        <button type="button" onclick="goToStep(1)" class="flex-1 bg-white border border-gray-200 text-gray-600 py-4 rounded-xl font-bold active:scale-[0.98] transition-transform">Back</button>
                        <button type="button" onclick="handleRegistration(event)" id="finish-btn" disabled class="flex-[2] bg-app-primary text-white py-4 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none">
                            Get Started <i data-lucide="party-popper" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

            </div>
        </form>
    </main>

    <script>
        lucide.createIcons();

        // 1. URL Params & Referrals Logic
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            let refCode = urlParams.get('ref');
            if (!refCode) {
                refCode = localStorage.getItem('rk_referrer_code');
            } else {
                localStorage.setItem('rk_referrer_code', refCode);
            }

            if (refCode) {
                const inputRef = document.getElementById('input-referrer');
                const refName = document.getElementById('ref-name');
                const refWelcome = document.getElementById('ref-welcome');
                const defaultWelcome = document.getElementById('default-welcome');

                if(inputRef) inputRef.value = refCode;
                if(refName) refName.innerText = refCode;
                if(refWelcome) refWelcome.classList.remove('hidden');
                if(defaultWelcome) defaultWelcome.classList.add('hidden');
            }

            const redirect = urlParams.get('redirect');
            if (redirect === 'cart') {
                const link = document.getElementById('login-link');
                link.href = 'login.php?redirect=cart';
            }
        });

        // Slider Logic
        let currentStep = 1;
        const totalSteps = 2;
        const slider = document.getElementById('slider');

        function updateProgress() {
            const percentage = Math.round((currentStep / totalSteps) * 100);
            document.getElementById('progress-bar').style.width = percentage + '%';
            document.getElementById('step-percent').innerText = percentage + '%';
            document.getElementById('step-label').innerText = `Mission ${currentStep}/${totalSteps}`;
        }

        function goToStep(step) {
            currentStep = step;
            const translateVal = (step - 1) * -50;
            slider.style.transform = `translateX(${translateVal}%)`;
            updateProgress();
        }

        const checkbox = document.getElementById('pledge-check');
        const finishBtn = document.getElementById('finish-btn');

        if(checkbox) {
            checkbox.addEventListener('change', () => {
                if(checkbox.checked) {
                    finishBtn.disabled = false;
                    finishBtn.classList.remove('disabled:opacity-50', 'disabled:cursor-not-allowed', 'disabled:shadow-none');
                } else {
                    finishBtn.disabled = true;
                    finishBtn.classList.add('disabled:opacity-50', 'disabled:cursor-not-allowed', 'disabled:shadow-none');
                }
            });
        }

        async function handleRegistration(e) {
            e.preventDefault();

            const originalText = finishBtn.innerHTML;
            finishBtn.disabled = true;
            finishBtn.innerHTML = '<span class="animate-spin mr-2"><i data-lucide="loader-2" class="w-4 h-4"></i></span> Setting up...';
            lucide.createIcons();

            const form = document.getElementById('reg-form');
            const formData = new FormData(form);

            const storedReferrer = localStorage.getItem('rk_referrer_code');
            if (storedReferrer) {
                formData.set('referrer', storedReferrer);
            }

            try {
                // Step 1: Register
                const response = await fetch(API_CONFIG.REGISTER, { method: 'POST', body: formData });

                if (response.status === 429) {
                    throw new Error("Too many attempts. Please wait 5 minutes.");
                }

                const data = await response.json();

                if (response.ok) {
                    // Step 2: Auto-Login (Simplified & Direct)
                    finishBtn.innerHTML = '<span class="animate-spin mr-2"><i data-lucide="loader-2" class="w-4 h-4"></i></span> Logging in...';

                    // Simple 1-second delay for DB propagation, then single login attempt
                    await new Promise(r => setTimeout(r, 1000));

                    try {
                        const loginRes = await fetch(API_CONFIG.LOGIN, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                username: formData.get('username'),
                                password: formData.get('password')
                            })
                        });

                        if (loginRes.ok) {
                            const result = await loginRes.json();
                            if (result.success) {
                                ['user_display_name', 'user_avatar_url', 'walletBalance', 'earningsBalance'].forEach(k => localStorage.removeItem(k));
                                if (result.user) {
                                    localStorage.setItem('user_email', result.user.email || '');
                                    localStorage.setItem('user_display_name', result.user.name || '');
                                }
                                localStorage.removeItem('token');
                                window.location.href = 'complete-profile.php';
                            } else {
                                window.location.href = 'login.php';
                            }
                        } else {
                            // Login failed (e.g. server error or auth mismatch)
                            // Pre-fill email on login page for better UX
                            const safeEmail = encodeURIComponent(formData.get('email'));
                            window.location.href = `login.php?filled_email=${safeEmail}`;
                        }
                    } catch (loginErr) {
                        console.error(loginErr);
                        window.location.href = 'login.php';
                    }

                } else {
                    throw new Error(data.message || 'Registration failed.');
                }
            } catch (error) {
                const safeMsg = error.message.replace(/<[^>]*>?/gm, '');
                alert(safeMsg);
                finishBtn.innerHTML = originalText;
                finishBtn.disabled = false;
                lucide.createIcons();
            }
        }

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