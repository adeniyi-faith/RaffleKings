<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Reset Password - RaffleKings</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'app-primary': '#2563eb',
                        'app-secondary': '#1e40af',
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <script src="config.js"></script>

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F3F4F6; }
    </style>
</head>
<body class="flex items-center justify-center min-h-[100dvh] px-4">

    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-extrabold text-gray-900">Reset Password</h1>
            <p class="text-sm text-gray-500 mt-2">Enter the code sent to your email.</p>
        </div>

        <div class="bg-white rounded-3xl shadow-xl p-8">
            <form id="reset-form" onsubmit="handleReset(event)" class="space-y-5">
                <div id="status-msg" class="hidden p-3 rounded-lg text-xs font-bold text-center"></div>

                <input type="hidden" name="email" id="email_hidden">

                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">6-Digit Code</label>
                    <input type="text" name="otp" placeholder="123456" maxlength="6" class="w-full bg-gray-50 border border-gray-100 rounded-xl px-4 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20 text-center tracking-[0.5em] font-bold text-xl" required>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">New Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="new-pass" placeholder="••••••••" class="w-full bg-gray-50 border border-gray-100 rounded-xl pl-4 pr-12 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20" required>
                        <button type="button" onclick="togglePass()" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400">
                            <i data-lucide="eye" id="eye-icon" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" id="submit-btn" class="w-full bg-gray-900 text-white py-4 rounded-xl font-bold shadow-lg shadow-gray-900/20 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
                    Update Password <i data-lucide="check-circle" class="w-4 h-4"></i>
                </button>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // Get email from URL
        const urlParams = new URLSearchParams(window.location.search);
        const email = urlParams.get('email');
        if(email) document.getElementById('email_hidden').value = email;
        else {
            alert('No email found. Redirecting...');
            window.location.href = 'forgot-password.php';
        }

        function togglePass() {
            const input = document.getElementById('new-pass');
            const icon = document.getElementById('eye-icon');
            if (input.type === "password") {
                input.type = "text";
                icon.setAttribute('data-lucide', 'eye-off');
            } else {
                input.type = "password";
                icon.setAttribute('data-lucide', 'eye');
            }
            lucide.createIcons();
        }

        async function handleReset(e) {
            e.preventDefault();
            const btn = document.getElementById('submit-btn');
            const msg = document.getElementById('status-msg');
            
            // Get form data
            const formData = new FormData(document.getElementById('reset-form'));
            const data = Object.fromEntries(formData.entries());

            btn.disabled = true;
            btn.innerHTML = 'Updating...';
            msg.className = 'hidden';

            try {
                const res = await fetch(API_CONFIG.RESET_PASSWORD, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();

                if (result.success) {
                    msg.className = 'bg-green-50 text-green-600 p-3 rounded-lg text-xs font-bold text-center block mb-4';
                    msg.innerText = result.message;
                    setTimeout(() => { window.location.href = 'login.php'; }, 2000);
                } else {
                    throw new Error(result.message || 'Update failed');
                }
            } catch (err) {
                msg.className = 'bg-red-50 text-red-600 p-3 rounded-lg text-xs font-bold text-center block mb-4';
                msg.innerText = err.message;
                btn.disabled = false;
                btn.innerHTML = 'Update Password <i data-lucide="check-circle" class="w-4 h-4"></i>';
                lucide.createIcons();
            }
        }
    </script>
</body>
</html>