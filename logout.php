<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out...</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { background-color: #F3F4F6; font-family: sans-serif; }
    </style>
</head>
<body class="flex items-center justify-center h-screen">

    <div class="text-center">
        <div class="w-16 h-16 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
        <h2 class="text-xl font-bold text-gray-800">Signing you out...</h2>
        <p class="text-sm text-gray-500 mt-2">Please wait a moment.</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // 1. Clear All Auth Data
            localStorage.removeItem('token');
            localStorage.removeItem('user_email');
            localStorage.removeItem('user_nicename');
            localStorage.removeItem('user_display_name');
            localStorage.removeItem('user_avatar_url');
            
            // Clear cached financial data to prevent flicker on next login
            localStorage.removeItem('walletBalance');
            localStorage.removeItem('earningsBalance');
            localStorage.removeItem('pendingCheckout');

            // 2. Redirect to Login
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 800); // Small delay for visual feedback
        });
    </script>
</body>
</html>