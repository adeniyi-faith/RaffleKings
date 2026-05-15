<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Privacy Policy - RaffleKings</title>
    
    <!-- PWA & Mobile Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'app-primary': '#2563eb',
                        'app-secondary': '#1e40af',
                        'dark-bg': '#0f172a',    
                        'dark-card': '#1e293b',  
                        'dark-border': '#334155' 
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

    <!-- Dark Mode Init -->
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
            document.querySelector('meta[name="theme-color"]').setAttribute('content', '#0f172a');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <style>
        * { -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Inter', sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>
<body class="bg-gray-50 dark:bg-dark-bg text-gray-900 dark:text-white h-screen w-full flex flex-col safe-top safe-bottom transition-colors duration-200">

    <!-- Top Navigation (Sticky) -->
    <div class="sticky top-0 z-50 px-5 pt-4 pb-2 bg-white/90 dark:bg-dark-bg/95 backdrop-blur-md border-b border-gray-100 dark:border-dark-border">
        <div class="flex justify-between items-center">
            <button onclick="history.back()" class="p-2 -ml-2 rounded-full text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                <i data-lucide="arrow-left" class="w-6 h-6"></i>
            </button>
            <h1 class="font-bold text-lg tracking-tight">Privacy Policy</h1>
            <div class="w-8"></div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto no-scrollbar p-6 pb-20">
        
        <div class="max-w-2xl mx-auto space-y-8">
            
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-green-50 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="shield-check" class="w-8 h-8"></i>
                </div>
                <h2 class="text-2xl font-black text-gray-900 dark:text-white">Your Data is Safe</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                    We value your trust. Here is exactly how we handle your information.
                </p>
            </div>

            <!-- 1. What We Collect -->
            <div class="bg-white dark:bg-dark-card rounded-2xl p-5 border border-gray-100 dark:border-gray-800 shadow-sm">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 uppercase tracking-wide flex items-center gap-2">
                    <i data-lucide="database" class="w-4 h-4 text-app-primary"></i> Data We Collect
                </h3>
                <div class="space-y-3">
                    <div class="flex gap-3">
                        <div class="mt-1 w-1.5 h-1.5 rounded-full bg-gray-400 shrink-0"></div>
                        <p class="text-sm text-gray-600 dark:text-gray-300"><strong class="text-gray-900 dark:text-white">Identity Data:</strong> Name, username, and profile picture (if uploaded).</p>
                    </div>
                    <div class="flex gap-3">
                        <div class="mt-1 w-1.5 h-1.5 rounded-full bg-gray-400 shrink-0"></div>
                        <p class="text-sm text-gray-600 dark:text-gray-300"><strong class="text-gray-900 dark:text-white">Contact Data:</strong> Email address and phone number for winner notifications.</p>
                    </div>
                    <div class="flex gap-3">
                        <div class="mt-1 w-1.5 h-1.5 rounded-full bg-gray-400 shrink-0"></div>
                        <p class="text-sm text-gray-600 dark:text-gray-300"><strong class="text-gray-900 dark:text-white">Financial Data:</strong> Bank account numbers provided strictly for withdrawal purposes. We do NOT store card PINs.</p>
                    </div>
                </div>
            </div>

            <!-- 2. How We Use It -->
            <div class="bg-white dark:bg-dark-card rounded-2xl p-5 border border-gray-100 dark:border-gray-800 shadow-sm">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 uppercase tracking-wide flex items-center gap-2">
                    <i data-lucide="cog" class="w-4 h-4 text-orange-500"></i> How We Use It
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed mb-4">
                    Your data is used solely to operate the RaffleKings service. Specifically:
                </p>
                <div class="grid gap-2">
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <i data-lucide="check" class="w-4 h-4 text-green-500"></i> To process ticket purchases and verify payments.
                    </div>
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <i data-lucide="check" class="w-4 h-4 text-green-500"></i> To contact you immediately if you win a prize.
                    </div>
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <i data-lucide="check" class="w-4 h-4 text-green-500"></i> To process withdrawals to your bank account.
                    </div>
                </div>
            </div>

            <!-- 3. Third Parties -->
            <div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Third-Party Sharing</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed mb-4">
                    We do not sell your personal data. We may share limited data with trusted partners solely for operational purposes:
                </p>
                <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-400 space-y-1 ml-2">
                    <li>Payment Processors (to verify transfers).</li>
                    <li>Email/SMS Providers (to send OTPs and alerts).</li>
                    <li>Security Services (to detect fraud).</li>
                </ul>
            </div>

            <!-- 4. Account Deletion -->
            <div class="bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/30 rounded-xl p-4">
                <h3 class="text-sm font-bold text-red-600 dark:text-red-400 mb-2">Data Deletion Rights</h3>
                <p class="text-xs text-red-800 dark:text-red-200 leading-relaxed">
                    You have the right to request the deletion of your account and associated data. To do so, please contact our support team. Note that transaction logs may be retained for legal and auditing purposes.
                </p>
            </div>

        </div>

        <div class="mt-12 pt-6 border-t border-gray-100 dark:border-gray-800 text-center">
            <a href="mailto:help@rafflekings.com.ng" class="inline-flex items-center gap-2 text-sm font-bold text-app-primary bg-blue-50 dark:bg-blue-900/30 px-4 py-2 rounded-full">
                <i data-lucide="mail" class="w-4 h-4"></i> Contact Privacy Officer
            </a>
        </div>

    </main>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>