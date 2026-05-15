<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Forgot Password - RaffleKings</title>
    
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
            <h1 class="text-2xl font-extrabold text-gray-900">Forgot Password?</h1>
            <p class="text-sm text-gray-500 mt-2">Enter your email to receive a 6-digit reset code.</p>
        </div>

        <div class="bg-white rounded-3xl shadow-xl p-8">
            <form id="forgot-form" onsubmit="handleForgot(event)" class="space-y-5">
                <div id="status-msg" class="hidden p-3 rounded-lg text-xs font-bold text-center"></div>

                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Email Address</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i data-lucide="mail" class="h-5 w-5 text-gray-300"></i>
                        </div>
                        <input type="email" name="email" id="email" placeholder="you@example.com" class="w-full bg-gray-50 border border-gray-100 rounded-xl pl-11 pr-4 py-3.5 outline-none focus:ring-2 focus:ring-app-primary/20 transition-all font-medium" required>
                    </div>
                </div>

                <button type="submit" id="submit-btn" class="w-full bg-gray-900 text-white py-4 rounded-xl font-bold shadow-lg shadow-gray-900/20 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
                    Send Code <i data-lucide="send" class="w-4 h-4"></i>
                </button>
            </form>
        </div>

        <div class="text-center mt-8">
            <a href="login.php" class="text-sm font-bold text-gray-500 hover:text-gray-800">Back to Login</a>
        </div>
    </div>

    <script>
        lucide.createIcons();

        async function handleForgot(e) {
            e.preventDefault();
            const btn = document.getElementById('submit-btn');
            const msg = document.getElementById('status-msg');
            const email = document.getElementById('email').value;

            btn.disabled = true;
            btn.innerHTML = 'Sending...';
            msg.className = 'hidden';

            try {
                const res = await fetch(API_CONFIG.FORGOT_PASSWORD, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: email })
                });
                const data = await res.json();

                if (data.success) {
                    msg.className = 'bg-green-50 text-green-600 p-3 rounded-lg text-xs font-bold text-center block mb-4';
                    msg.innerText = data.message;
                    setTimeout(() => {
                        window.location.href = `reset-password.php?email=${encodeURIComponent(email)}`;
                    }, 1500);
                } else {
                    throw new Error(data.message || 'Request failed');
                }
            } catch (err) {
                msg.className = 'bg-red-50 text-red-600 p-3 rounded-lg text-xs font-bold text-center block mb-4';
                msg.innerText = err.message;
                btn.disabled = false;
                btn.innerHTML = 'Send Code <i data-lucide="send" class="w-4 h-4"></i>';
                lucide.createIcons();
            }
        }
    </script>
</body>
</html>