<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Secure Checkout | RaffleKings</title>

    <!-- PWA & Mobile Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="https://cdn-icons-png.flaticon.com/512/616/616490.png">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'app-primary': '#2563eb',
                        'app-bg': '#f8fafc',
                        'dark-bg': '#0f172a',
                        'dark-card': '#1e293b',
                        'dark-border': '#334155'
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    animation: { 'splash-bounce': 'splash-bounce 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards' },
                    keyframes: { 'splash-bounce': { '0%': { transform: 'scale(0.5)', opacity: '0' }, '100%': { transform: 'scale(1)', opacity: '1' } } }
                }
            }
        }
    </script>

    <!-- Lucide Icons & Fonts -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Configuration -->
    <script src="config.js"></script>

    <style>
        * { -webkit-tap-highlight-color: transparent; }
        html, body { height: 100dvh; width: 100%; margin: 0; overflow: hidden; overscroll-behavior-y: none; }
        body { font-family: 'Inter', sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>

    <script>
        // Theme Init
        const localTheme = localStorage.getItem('theme');
        const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (localTheme === 'dark' || (!localTheme && systemDark)) {
            document.documentElement.classList.add('dark');
        }
        window.addEventListener('load', () => lucide.createIcons());
    </script>
</head>

<body class="bg-gray-50 dark:bg-dark-bg text-gray-900 dark:text-white w-full flex flex-col transition-colors duration-200">

    <!-- 1. Checkout Header -->
    <header class="bg-white dark:bg-dark-bg/95 dark:border-dark-border px-5 py-4 border-b border-gray-100 dark:border-gray-800 sticky top-0 z-30 flex items-center gap-3 backdrop-blur-md flex-shrink-0"
            style="padding-top: calc(env(safe-area-inset-top) + 1rem);">
        <button onclick="triggerExitIntent()" class="p-1 -ml-1 text-gray-400 hover:text-gray-600 dark:hover:text-white transition-colors">
            <i data-lucide="arrow-left" class="w-6 h-6"></i>
        </button>
        <h2 class="text-lg font-black text-gray-900 dark:text-white tracking-tight">Secure Checkout</h2>
        <div class="ml-auto">
            <i data-lucide="lock" class="w-4 h-4 text-app-primary"></i>
        </div>
    </header>

    <!-- Scrollable Content Area -->
    <main class="flex-1 overflow-y-auto no-scrollbar pb-36 relative transition-colors duration-200 w-full">
        <div class="px-5 pt-6">

            <!-- ⭐ CELEBRATION BANNER (Integrated from Marketing UI) ⭐ -->
            <div id="celebration-banner" class="hidden mb-6 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl p-4 shadow-lg relative overflow-hidden transform transition-all duration-500 hover:scale-[1.01]">
                <!-- Decorative Blur -->
                <div class="absolute -right-4 -top-4 w-20 h-20 bg-white/20 rounded-full blur-xl"></div>

                <div class="relative z-10 flex items-center gap-3">
                    <div class="bg-white/20 p-2.5 rounded-full backdrop-blur-sm shadow-inner">
                        <span class="text-xl">🏆</span>
                    </div>
                    <div>
                        <h4 class="font-black text-lg tracking-tight">SMART CHOICE!</h4>
                        <p class="text-xs text-green-50 font-medium">
                            You saved <span id="celebration-amount" class="font-bold bg-white/20 px-1.5 py-0.5 rounded text-white shadow-sm">₦0</span> with the Golden Offer.
                        </p>
                    </div>
                </div>
            </div>
            <!-- End Celebration Banner -->

            <!-- 2. Order Summary Card -->
            <div class="bg-white dark:bg-dark-card p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 mb-6 transition-colors duration-200">
                <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-4 border-b border-gray-50 dark:border-gray-700 pb-2">Order Summary</h3>

                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h4 class="font-bold text-gray-900 dark:text-white text-lg">Ticket Purchase</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1"><span id="ticket-qty">--</span> Tickets Bundle</p>

                        <!-- Discount Badge (Integrated) -->
                        <div id="discount-badge" class="hidden mt-2 inline-flex items-center gap-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 px-2 py-1 rounded text-[10px] font-bold uppercase">
                            <i data-lucide="tag" class="w-3 h-3"></i> <span id="discount-text"></span>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="block font-black text-xl text-app-primary tracking-tight" id="total-amount">...</span>
                        <span id="original-amount" class="hidden text-xs text-gray-400 line-through font-medium"></span>
                    </div>
                </div>

                <div class="bg-gray-50 dark:bg-dark-bg/50 rounded-xl p-3 border border-gray-100 dark:border-gray-700">
                    <p class="text-[10px] text-gray-400 dark:text-gray-500 mb-2 font-medium uppercase">Selected Numbers</p>
                    <div class="flex flex-wrap gap-2" id="numbers-display"></div>
                </div>
            </div>

            <!-- 3. Payment Methods -->
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-3 px-1">Select Payment Method</h3>

            <div class="space-y-4">

                <!-- Wallet -->
                <div id="wallet-option" onclick="selectWallet()" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 rounded-xl p-4 transition-all relative overflow-hidden cursor-pointer active:scale-[0.98]">
                    <div class="flex items-center gap-4 relative z-10">
                        <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center text-app-primary">
                            <i data-lucide="wallet" class="w-5 h-5"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Spending Wallet</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Balance: <span id="wallet-balance" class="font-bold">...</span></p>
                        </div>
                        <div id="wallet-radio" class="w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 flex items-center justify-center transition-all"></div>
                    </div>
                    <div id="wallet-actions" class="hidden mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                          <!-- Insufficient Funds Msg -->
                        <div id="wallet-topup-msg" class="hidden">
                            <p class="text-xs text-red-500 flex items-center gap-1 font-bold mb-2">
                                <i data-lucide="alert-circle" class="w-3 h-3"></i> Insufficient Balance
                            </p>
                            <a href="topup.php" class="block w-full text-center bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-xs font-bold py-2.5 rounded-lg shadow-sm">
                                Top Up Wallet Now
                            </a>
                        </div>
                          <!-- Convert Msg -->
                        <div id="wallet-convert-msg" class="hidden">
                             <p class="text-xs text-orange-500 flex items-center gap-1 font-bold mb-2">
                                <i data-lucide="alert-triangle" class="w-3 h-3"></i> Wallet Low, but Winnings Available
                            </p>
                            <button onclick="openConvertModal(event)" class="w-full bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300 border border-orange-200 dark:border-orange-800 text-xs font-bold py-2.5 rounded-lg flex items-center justify-center gap-2">
                                <i data-lucide="refresh-cw" class="w-3 h-3"></i> Move Winnings to Spending
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Earnings -->
                <div id="earnings-option" onclick="selectEarnings()" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 rounded-xl p-4 transition-all relative overflow-hidden cursor-pointer active:scale-[0.98]">
                    <div class="flex items-center gap-4 relative z-10">
                        <div class="w-10 h-10 rounded-full bg-yellow-50 dark:bg-yellow-900/20 flex items-center justify-center text-yellow-600 dark:text-yellow-500">
                            <i data-lucide="award" class="w-5 h-5"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Pay with Winnings</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Balance: <span id="earnings-balance" class="font-bold">...</span></p>
                        </div>
                        <div id="earnings-radio" class="w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 flex items-center justify-center transition-all"></div>
                    </div>
                    <div id="earnings-warning" class="hidden mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 animate-pulse">
                        <p class="text-xs text-red-500 flex items-center gap-1 font-bold">
                            <i data-lucide="alert-circle" class="w-3 h-3"></i> Insufficient Winnings
                        </p>
                    </div>
                </div>

                <!-- Bank Transfer -->
                <div id="bank-option-container">
                    <div id="bank-option" onclick="selectBank()" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 rounded-xl p-4 transition-all cursor-pointer active:scale-[0.98]">
                        <div class="flex items-center gap-4 mb-2">
                            <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center text-green-600 dark:text-green-500">
                                <i data-lucide="landmark" class="w-5 h-5"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold text-gray-900 dark:text-white">Direct Bank Transfer</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Instant Verification ⚡</p>
                            </div>
                            <div id="bank-radio" class="w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 flex items-center justify-center transition-all"></div>
                        </div>

                        <!-- Bank Details Area -->
                        <div id="bank-details-area" class="hidden mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 cursor-default" onclick="event.stopPropagation()">
                            <div class="bg-gray-50 dark:bg-dark-bg p-4 rounded-xl border border-gray-200 dark:border-gray-700 mb-4 relative">
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 uppercase mb-1 font-bold">Bank Name</p>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3" id="bank-name">Kuda MFB</p>

                                <p class="text-[10px] text-gray-400 dark:text-gray-500 uppercase mb-1 font-bold">Account Number</p>
                                <div class="flex items-center gap-2">
                                    <p class="text-xl font-mono font-bold text-gray-900 dark:text-white tracking-wider" id="account-number">300 325 9510 </p>
                                    <button onclick="copyAccount()" class="text-app-primary p-1.5 bg-blue-50 dark:bg-blue-900/30 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors">
                                        <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" id="account-name">RKS Digital Innovations</p>
                            </div>

                            <!-- Order ID for Narration -->
                            <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-100 dark:border-orange-900/50 rounded-xl p-3 text-center mb-4">
                                <p class="text-[10px] text-orange-500 dark:text-orange-400 uppercase font-bold mb-1">Important: Use as Narration</p>
                                <div class="flex items-center justify-center gap-2">
                                    <span id="generated-order-id" class="font-mono text-lg font-bold text-orange-600 dark:text-orange-400">...</span>
                                    <button onclick="copyOrderId()" class="text-orange-400 hover:text-orange-600 active:scale-90 transition-transform">
                                        <i data-lucide="copy" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-xs font-bold text-gray-700 dark:text-gray-300">Upload Receipt for Verification</label>
                                <div class="relative">
                                    <input type="file" id="proof-upload" class="hidden" accept="image/*" onchange="previewProof(this)">
                                    <label for="proof-upload" class="flex flex-col items-center justify-center w-full h-24 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-dark-bg hover:border-app-primary transition-colors bg-white dark:bg-dark-card">
                                        <div id="upload-placeholder" class="flex flex-col items-center">
                                            <i data-lucide="scan-line" class="w-6 h-6 text-gray-400 dark:text-gray-500 mb-1"></i>
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400">Tap to upload receipt</p>
                                        </div>
                                        <img id="proof-preview" class="hidden w-full h-full object-cover rounded-xl opacity-80">
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="bank-disabled-msg" class="hidden bg-gray-50 dark:bg-dark-card/50 border border-gray-100 dark:border-gray-800 rounded-xl p-4 text-center">
                    <div class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 mx-auto flex items-center justify-center mb-2">
                        <i data-lucide="landmark" class="w-5 h-5 text-gray-300 dark:text-gray-600"></i>
                    </div>
                    <p class="text-xs font-bold text-gray-400 dark:text-gray-500">Bank Transfer Unavailable</p>
                    <p class="text-[10px] text-gray-300 dark:text-gray-600 mt-1">Minimum ₦1,000 required for bank transfers.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Sticky Pay Button -->
    <div class="fixed bottom-0 left-0 w-full bg-white dark:bg-dark-bg/95 backdrop-blur-md border-t border-gray-100 dark:border-dark-border p-4 safe-bottom z-50 shadow-[0_-5px_20px_rgba(0,0,0,0.05)] transition-colors duration-200">
        <div class="flex items-center gap-4 max-w-md mx-auto">
            <div class="flex-1">
                <p class="text-[10px] text-gray-400 dark:text-gray-500 font-bold uppercase tracking-wide">Total to Pay</p>
                <p class="text-2xl font-black text-gray-900 dark:text-white tracking-tight" id="sticky-total">...</p>
            </div>
            <button onclick="processPayment()" id="pay-btn" class="flex-[2] bg-gray-200 dark:bg-gray-800 text-gray-400 dark:text-gray-500 py-3.5 rounded-xl font-bold text-sm shadow-none cursor-not-allowed flex items-center justify-center gap-2 transition-all duration-300" disabled>
                Select Method <i data-lucide="chevron-up" class="w-4 h-4"></i>
            </button>
        </div>
    </div>

    <!-- Processing Modal -->
    <div id="processing-modal" class="fixed inset-0 bg-black/80 z-[60] hidden flex items-center justify-center backdrop-blur-sm p-5">
        <div class="bg-white dark:bg-dark-card rounded-3xl p-8 w-full max-w-sm text-center border border-gray-100 dark:border-gray-800 shadow-2xl">
            <div class="relative w-20 h-20 mx-auto mb-6">
                <div class="absolute inset-0 border-4 border-gray-100 dark:border-gray-700 rounded-full"></div>
                <div class="absolute inset-0 border-4 border-app-primary border-t-transparent rounded-full animate-spin"></div>
                <div class="absolute inset-0 flex items-center justify-center">
                    <i data-lucide="shield-check" class="w-8 h-8 text-app-primary animate-pulse"></i>
                </div>
            </div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1">Processing...</h2>
            <p class="text-gray-500 dark:text-gray-400 text-xs mb-4">Verifying details securely</p>
        </div>
    </div>

    <!-- Convert Funds Modal -->
    <div id="convert-modal" class="fixed inset-0 bg-black/80 z-[70] hidden flex items-center justify-center backdrop-blur-sm p-5">
        <div class="bg-white dark:bg-dark-card rounded-3xl p-6 w-full max-w-sm border border-gray-100 dark:border-gray-800 shadow-2xl transform scale-95 transition-transform">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Move Funds</h3>
                <button onclick="closeConvertModal()" class="text-gray-400"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>

            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                You need <span id="convert-needed" class="font-bold text-gray-900 dark:text-white">...</span> more in your spending wallet. Move it from your winnings?
            </p>

            <div class="bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-xl mb-6 flex justify-between items-center">
                <span class="text-xs font-medium text-yellow-700 dark:text-yellow-400">Winnings Available:</span>
                <span class="text-sm font-bold text-yellow-800 dark:text-yellow-300" id="convert-available">...</span>
            </div>

            <button onclick="executeTransfer()" id="confirm-convert-btn" class="w-full bg-app-primary text-white py-3.5 rounded-xl font-bold text-sm shadow-lg flex items-center justify-center gap-2">
                Yes, Move & Pay <i data-lucide="arrow-right-left" class="w-4 h-4"></i>
            </button>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="success-modal" class="fixed inset-0 bg-black/90 z-[100] hidden flex items-center justify-center backdrop-blur-md p-4 transition-opacity duration-300">
        <div class="bg-white dark:bg-dark-card rounded-[2rem] w-full max-w-sm mx-auto overflow-hidden transform scale-90 opacity-0 transition-all duration-300 relative border border-gray-100 dark:border-gray-800 shadow-2xl flex flex-col min-h-[500px]" id="success-content">
            <div class="flex-1 flex flex-col items-center justify-center p-8 text-center relative overflow-hidden">
                <div class="absolute inset-0 opacity-5 bg-[radial-gradient(#2563eb_1px,transparent_1px)] [background-size:16px_16px]"></div>
                <div class="relative mb-8 mt-4 animate-splash-bounce">
                    <div id="splash-ring-1" class="absolute inset-0 bg-green-100 dark:bg-green-900/20 rounded-full animate-ping opacity-75"></div>
                    <div id="splash-ring-2" class="absolute -inset-4 bg-green-50 dark:bg-green-900/10 rounded-full animate-pulse"></div>
                    <div id="icon-bg" class="relative w-24 h-24 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center shadow-inner ring-4 ring-white dark:ring-dark-card">
                        <i id="success-icon" data-lucide="check" class="w-10 h-10 text-green-600 dark:text-green-500 stroke-[3]"></i>
                    </div>
                </div>

                <h2 class="text-2xl font-black text-gray-900 dark:text-white mb-3 tracking-tight leading-tight" id="success-title">Payment Approved!</h2>
                <p class="text-gray-500 dark:text-gray-400 text-sm font-medium leading-relaxed max-w-[260px] mx-auto" id="success-msg">Your tickets have been successfully generated and locked in.</p>
            </div>

            <div class="bg-gray-50 dark:bg-dark-bg/50 p-6 border-t border-gray-100 dark:border-gray-800 flex-shrink-0">
                <div id="upsell-container" class="hidden mb-5 relative z-10">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 text-center transform transition-transform hover:scale-[1.02] duration-300">
                        <span id="upsell-badge" class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest shadow-sm"></span>
                        <h3 id="upsell-headline" class="text-base font-black text-gray-900 dark:text-white mb-1 leading-tight mt-1"></h3>
                        <p id="upsell-body" class="text-[11px] text-gray-500 dark:text-gray-400 mb-3 leading-snug px-2"></p>
                        <a id="upsell-cta" href="#" class="w-full py-2.5 rounded-xl font-bold text-xs shadow-md flex items-center justify-center gap-2 active:scale-95 transition-transform"></a>
                    </div>
                </div>
                <a href="my-tickets.php" class="w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-4 rounded-xl font-bold text-sm shadow-lg hover:shadow-xl hover:scale-[1.01] transition-all flex items-center justify-center gap-2">
                    View My Tickets <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Exit Intent Modal -->
    <div id="exit-intent-modal" class="fixed inset-0 bg-black/90 z-[70] hidden flex items-center justify-center p-4 backdrop-blur-sm opacity-0 transition-opacity duration-300 pointer-events-none">
        <div class="bg-white dark:bg-gray-900 w-full max-w-sm rounded-3xl overflow-hidden relative shadow-2xl transform scale-95 transition-transform duration-300">
            <div class="bg-red-600 p-4 text-center relative overflow-hidden">
                <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/diagmonds-light.png')] opacity-20"></div>
                <div class="relative z-10">
                    <h2 class="text-2xl font-black text-white italic tracking-tighter">WAIT! 🛑</h2>
                    <p class="text-red-100 text-xs font-medium">Your tickets are about to be released...</p>
                </div>
                <button onclick="closeExitModal(true)" class="absolute top-3 right-3 text-white/70 hover:text-white">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="flex justify-center -space-x-3 mb-6 overflow-hidden py-2" id="exit-tickets">
                    <div class="w-10 h-14 bg-gray-100 border border-gray-300 rounded flex items-center justify-center text-xs font-bold text-gray-400 rotate-[-10deg] shadow-sm">?</div>
                    <div class="w-10 h-14 bg-gray-100 border border-gray-300 rounded flex items-center justify-center text-xs font-bold text-gray-400 rotate-[5deg] shadow-sm">?</div>
                </div>
                <div class="text-center space-y-4">
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-bold tracking-widest">Potential Win</p>
                        <h3 class="text-3xl font-black text-gray-900 dark:text-white" id="exit-potential">₦500,000</h3>
                    </div>
                    <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-3 text-left flex items-start gap-3">
                        <div class="mt-0.5 text-yellow-600"><i data-lucide="alert-triangle" class="w-4 h-4"></i></div>
                        <div>
                            <p class="text-xs font-bold text-yellow-800">High Risk of Loss</p>
                            <p class="text-left text-[10px] text-yellow-700 leading-snug">
                                3 other people are viewing this raffle right now. If you leave, your numbers will be returned to the pool instantly.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="mt-6 space-y-3">
                    <button onclick="closeExitModal()" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl shadow-lg shadow-green-200 flex items-center justify-center gap-2 transition-all active:scale-95">
                        Continue to Checkout <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                    <button onclick="closeExitModal(true)" class="w-full text-center text-xs text-gray-400 hover:text-gray-600 py-2">
                        I'll take the risk and leave
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        let tickets = parseInt(urlParams.get('tickets')) || 0;
        const numbersRaw = urlParams.get('numbers') || '';
        const numbers = numbersRaw ? numbersRaw.split(',') : [];
        const raffleId = urlParams.get('raffle_id') || 0;
        let amount = 0;

        // 2. Render Initial State
        document.getElementById('ticket-qty').innerText = tickets;
        document.getElementById('total-amount').innerText = 'Validating...';
        document.getElementById('sticky-total').innerText = '...';

        const numbersContainer = document.getElementById('numbers-display');
        if (numbers.length > 0) {
            numbers.forEach(num => {
                const span = document.createElement('span');
                span.className = "bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-200 font-mono font-bold text-xs px-2.5 py-1 rounded-md shadow-sm";
                span.innerText = num;
                numbersContainer.appendChild(span);
            });
        } else {
            numbersContainer.innerHTML = '<span class="text-xs text-gray-400 italic">Random Selection</span>';
        }

        // 4. Wallet Data
        let walletBal = parseFloat(localStorage.getItem('walletBalance')) || 0;
        let earningsBal = parseFloat(localStorage.getItem('earningsBalance')) || 0;

        const walletBalanceEl = document.getElementById('wallet-balance');
        const earningsBalanceEl = document.getElementById('earnings-balance');

        walletBalanceEl.innerText = '₦' + walletBal.toLocaleString();
        if(earningsBalanceEl) earningsBalanceEl.innerText = '₦' + earningsBal.toLocaleString();

        let selectedMethod = null;
        let currentOrderId = '';
        const walletOption = document.getElementById('wallet-option');
        const earningsOption = document.getElementById('earnings-option');
        const bankOption = document.getElementById('bank-option');
        const payBtn = document.getElementById('pay-btn');

        let weeklyRaffleId = null;
        let dailyRaffleId = null;

        const DISCOUNT_TIERS = { 1: 1.0,  2: 0.75, 3: 0.65, 5: 0.60, 10: 0.55 };
        const BULK_DISCOUNT = 0.50;

        // *** UPDATED DISCOUNT LOGIC FOR GOLDEN BOX ***
        function calculateDiscountedPrice(qty, uPrice) {
            const originalPrice = qty * uPrice;
            let multiplier = 1.0;

            // 1. Structural Discount (Applied First)
            if (uPrice <= 200) {
                if (qty >= 2) multiplier = 0.90;
            } else {
                if (DISCOUNT_TIERS[qty]) multiplier = DISCOUNT_TIERS[qty];
                else if (qty > 10) multiplier = BULK_DISCOUNT;
            }

            // 2. Golden Box Logic (Compound Discount)
            const hasGoldenDiscount = urlParams.get('discount_applied') === 'true' ||
                                      (localStorage.getItem('rk_cart_session') && localStorage.getItem('rk_cart_session').includes('"discount_applied":true'));

            if (hasGoldenDiscount) {
                // Calculate intermediate price (Post-Structure, Pre-Golden) for savings display
                // Note: removed rounding to 10 here too
                const structPrice = Math.ceil(originalPrice * multiplier);

                // Apply Compounding 10%
                multiplier = multiplier * 0.90;

                // Final Price (No rounding to 10)
                const finalPrice = Math.ceil(originalPrice * multiplier);

                // Calculate ONLY the Golden Savings (Structure Price - Final Price)
                const goldenSavings = structPrice - finalPrice;

                document.getElementById('celebration-banner').classList.remove('hidden');
                document.getElementById('celebration-amount').innerText = '₦' + goldenSavings.toLocaleString();
            }

            // REMOVED ROUNDING TO NEAREST 10 HERE
            const discounted = Math.ceil(originalPrice * multiplier);
            return { original: originalPrice, discounted: discounted, multiplier: multiplier };
        }

        (function() {
            currentOrderId = 'ORD-' + Math.floor(1000 + Math.random() * 9000);
            const el = document.getElementById('generated-order-id');
            if(el) el.innerText = currentOrderId;
        })();

        document.addEventListener('DOMContentLoaded', async () => {
            const token = localStorage.getItem('token');
            if (tickets > 0 || true) initExitIntent();

            await verifyRealPrice();
            fetchUpsellIds();

            try {
                const settingsUrl = (typeof API_CONFIG !== 'undefined' && API_CONFIG.SETTINGS) ? API_CONFIG.SETTINGS : 'ajax-router.php?action=get_settings';
                const res = await fetch(settingsUrl);
                if(res.ok) {
                    const settings = await res.json();
                    if(settings.account_number && settings.account_number !== "0000000000") {
                        document.getElementById('bank-name').innerText = settings.bank_name;
                        document.getElementById('account-number').innerText = settings.account_number;
                        document.getElementById('account-name').innerText = settings.account_name;
                    }
                }
            } catch(e) { console.error("Error fetching bank settings", e); }

            try {
                const balanceUrl = (typeof API_CONFIG !== 'undefined' && API_CONFIG.BALANCE) ? API_CONFIG.BALANCE : 'ajax-router.php?action=get_balance';
                const res = await fetch(balanceUrl, {
                        headers: { 'Authorization': `Bearer ${token}` }
                });
                if(res.ok) {
                    const data = await res.json();
                    walletBal = parseFloat(data.wallet);
                    earningsBal = parseFloat(data.earnings);
                    walletBalanceEl.innerText = '₦' + walletBal.toLocaleString();
                    if(earningsBalanceEl) earningsBalanceEl.innerText = '₦' + earningsBal.toLocaleString();
                    localStorage.setItem('walletBalance', walletBal);
                    localStorage.setItem('earningsBalance', earningsBal);
                    if (selectedMethod === 'wallet' || selectedMethod === 'earnings') checkFunds();
                }
            } catch(e) { console.error("Error fetching balance", e); }
        });

        async function verifyRealPrice() {
            try {
                const res = await fetch(`ajax-router.php?action=get_raffle&id=${encodeURIComponent(raffleId)}`);
                if (!res.ok) throw new Error("Invalid Raffle");
                const data = await res.json();
                const pricePerTicket = parseFloat(data.raffle_meta.price);
                const prices = calculateDiscountedPrice(tickets, pricePerTicket);
                amount = prices.discounted;
                const original = prices.original;
                const savings = original - amount;

                // --- NEW: Update Exit Intent "Potential Win" ---
                const meta = data.raffle_meta || {};
                const title = data.title.rendered || '';
                // Matches logic from raffles.php to get grand prize
                const potentialWin = meta.grand_prize || title || '₦500,000';

                const exitEl = document.getElementById('exit-potential');
                if(exitEl) exitEl.innerText = potentialWin;
                // -----------------------------------------------

                document.getElementById('total-amount').innerText = '₦' + amount.toLocaleString();
                document.getElementById('sticky-total').innerText = '₦' + amount.toLocaleString();

                if (savings > 0) {
                    const pct = Math.round((1 - prices.multiplier) * 100);
                    document.getElementById('discount-badge').classList.remove('hidden');
                    document.getElementById('discount-text').innerText = `SAVED ₦${savings.toLocaleString()} (${pct}% OFF)`;
                    document.getElementById('original-amount').innerText = '₦' + original.toLocaleString();
                    document.getElementById('original-amount').classList.remove('hidden');
                }

                if (amount < 1000) {
                    document.getElementById('bank-option-container').classList.add('hidden');
                    document.getElementById('bank-disabled-msg').classList.remove('hidden');
                }
                const token = localStorage.getItem('token');
                if(token) syncCheckoutToBackend(token);
            } catch (e) { console.error("Security Check Failed", e); }
        }

        async function fetchUpsellIds() {
             try {
                const res = await fetch('ajax-router.php?action=get_raffles&per_page=20');
                if(!res.ok) return;
                const rafflesPayload = await res.json();
                const raffles = rafflesPayload && Object.prototype.hasOwnProperty.call(rafflesPayload, 'data') ? rafflesPayload.data : rafflesPayload;

                const weekly = raffles.find(r => {
                    const p = parseFloat(r.raffle_meta?.price || 0);
                    const t = r.title.rendered.toLowerCase();
                    const isSoldOut = (r.raffle_meta?.is_sold_out === '1' || r.raffle_meta?.is_sold_out === true);
                    if(isSoldOut) return false;
                    return (p >= 500) || t.includes('weekly') || t.includes('jackpot');
                });

                const daily = raffles.find(r => {
                    const p = parseFloat(r.raffle_meta?.price || 0);
                    const t = r.title.rendered.toLowerCase();
                    const isSoldOut = (r.raffle_meta?.is_sold_out === '1' || r.raffle_meta?.is_sold_out === true);
                    if(isSoldOut) return false;
                    return (p <= 200 && p > 0) || t.includes('daily');
                });

                if(weekly) weeklyRaffleId = weekly.id;
                if(daily) dailyRaffleId = daily.id;
            } catch(e) { console.log("Upsell fetch error", e); }
        }

        // Updated Sync Function with LocalStorage backup for Golden Box
        async function syncCheckoutToBackend(token) {
            const cartItem = {
                id: 'checkout_session',
                name: `${tickets} Tickets`,
                price: amount,
                numbers: numbers
            };

            // Save to localStorage for Golden Box trigger
            const cartSession = {
                cart: [cartItem],
                total: amount,
                raffle_id: raffleId,
                ticket_count: tickets,
                discount_applied: urlParams.get('discount_applied') === 'true',
                created_at: Date.now()
            };

            localStorage.setItem('rk_cart_session', JSON.stringify(cartSession));

            // Also sync to backend
            try {
                await fetch((typeof API_CONFIG !== 'undefined' && API_CONFIG.CART_SYNC) ? API_CONFIG.CART_SYNC : 'ajax-router.php?action=cart_sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ cart: [cartItem], total: amount })
                });
            } catch (e) {
                console.error("Sync failed", e);
            }
        }

        function checkFunds() {
            document.getElementById('wallet-actions').classList.add('hidden');
            document.getElementById('wallet-topup-msg').classList.add('hidden');
            document.getElementById('wallet-convert-msg').classList.add('hidden');
            if(document.getElementById('earnings-warning')) document.getElementById('earnings-warning').classList.add('hidden');

            walletOption.classList.remove('bg-red-50', 'dark:bg-red-900/20', 'border-red-200', 'dark:border-red-900');
            if(earningsOption) earningsOption.classList.remove('bg-red-50', 'dark:bg-red-900/20', 'border-red-200', 'dark:border-red-900');

            if (selectedMethod === 'wallet') {
                if (walletBal < amount) {
                    const deficit = amount - walletBal;
                    document.getElementById('wallet-actions').classList.remove('hidden');
                    walletOption.classList.add('bg-red-50', 'dark:bg-red-900/20', 'border-red-200', 'dark:border-red-900');

                    if (earningsBal >= deficit) {
                        document.getElementById('wallet-convert-msg').classList.remove('hidden');
                        disablePayBtn('Insufficient Funds in Spending');
                    } else {
                        document.getElementById('wallet-topup-msg').classList.remove('hidden');
                        disablePayBtn('Insufficient Balance');
                    }
                } else {
                    enablePayBtn();
                }
            } else if (selectedMethod === 'earnings') {
                if (earningsBal < amount) {
                    document.getElementById('earnings-warning').classList.remove('hidden');
                    earningsOption.classList.add('bg-red-50', 'dark:bg-red-900/20', 'border-red-200', 'dark:border-red-900');
                    disablePayBtn('Insufficient Winnings');
                } else {
                    enablePayBtn();
                }
            }
        }

        function openConvertModal(e) {
            e.stopPropagation();
            const deficit = amount - walletBal;
            document.getElementById('convert-needed').innerText = '₦' + deficit.toLocaleString();
            document.getElementById('convert-available').innerText = '₦' + earningsBal.toLocaleString();
            document.getElementById('convert-modal').classList.remove('hidden');
        }

        function closeConvertModal() {
            document.getElementById('convert-modal').classList.add('hidden');
        }

        async function executeTransfer() {
            const deficit = amount - walletBal;
            const btn = document.getElementById('confirm-convert-btn');
            btn.innerHTML = 'Processing...';
            btn.disabled = true;

            const token = localStorage.getItem('token');
            try {
                const res = await fetch((typeof API_CONFIG !== 'undefined' && API_CONFIG.TRANSFER) ? API_CONFIG.TRANSFER : 'ajax-router.php?action=transfer', {
                    method: 'POST',
                    headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ amount: deficit })
                });

                const result = await res.json();

                if (result.success) {
                    walletBal = walletBal + deficit;
                    earningsBal = earningsBal - deficit;
                    localStorage.setItem('walletBalance', walletBal);
                    localStorage.setItem('earningsBalance', earningsBal);
                    walletBalanceEl.innerText = '₦' + walletBal.toLocaleString();
                    if(earningsBalanceEl) earningsBalanceEl.innerText = '₦' + earningsBal.toLocaleString();
                    closeConvertModal();
                    checkFunds();
                } else {
                    alert("Transfer Failed: " + result.message);
                }
            } catch (e) {
                alert("Error connecting to server.");
            } finally {
                btn.innerHTML = 'Yes, Move & Pay <i data-lucide="arrow-right-left" class="w-4 h-4"></i>';
                btn.disabled = false;
            }
        }

        function enablePayBtn() {
            payBtn.disabled = false;
            payBtn.classList.remove('bg-gray-200', 'dark:bg-gray-800', 'text-gray-400', 'dark:text-gray-500', 'cursor-not-allowed', 'shadow-none');
            payBtn.classList.add('bg-app-primary', 'text-white', 'shadow-lg');
            payBtn.innerHTML = `Pay ₦${amount.toLocaleString()} Now`;
        }

        function disablePayBtn(msg) {
            payBtn.disabled = true;
            payBtn.classList.add('bg-gray-200', 'dark:bg-gray-800', 'text-gray-400', 'dark:text-gray-500', 'cursor-not-allowed', 'shadow-none');
            payBtn.classList.remove('bg-app-primary', 'text-white', 'shadow-lg');
            payBtn.innerHTML = msg;
        }

        function resetOptions() {
            walletOption.classList.remove('border-app-primary', 'ring-1', 'ring-app-primary', 'bg-red-50', 'dark:bg-red-900/20', 'border-red-200', 'dark:border-red-900');
            document.getElementById('wallet-radio').className = "w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 flex items-center justify-center";
            document.getElementById('wallet-radio').innerHTML = '';
            document.getElementById('wallet-actions').classList.add('hidden');

            if(earningsOption) {
                earningsOption.classList.remove('border-app-primary', 'ring-1', 'ring-app-primary', 'bg-red-50', 'dark:bg-red-900/20', 'border-red-200', 'dark:border-red-900');
                document.getElementById('earnings-radio').className = "w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 flex items-center justify-center";
                document.getElementById('earnings-radio').innerHTML = '';
                document.getElementById('earnings-warning').classList.add('hidden');
            }

            bankOption.classList.remove('border-app-primary', 'ring-1', 'ring-app-primary');
            document.getElementById('bank-radio').className = "w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 flex items-center justify-center";
            document.getElementById('bank-radio').innerHTML = '';
            document.getElementById('bank-details-area').classList.add('hidden');
        }

        function selectWallet() {
            resetOptions();
            selectedMethod = 'wallet';
            walletOption.classList.add('border-app-primary', 'ring-1', 'ring-app-primary');
            document.getElementById('wallet-radio').className = "w-5 h-5 rounded-full bg-app-primary border-transparent flex items-center justify-center text-white transition-all";
            document.getElementById('wallet-radio').innerHTML = '<i data-lucide="check" class="w-3 h-3"></i>';
            checkFunds();
            lucide.createIcons();
        }

        function selectEarnings() {
            resetOptions();
            selectedMethod = 'earnings';
            earningsOption.classList.add('border-app-primary', 'ring-1', 'ring-app-primary');
            document.getElementById('earnings-radio').className = "w-5 h-5 rounded-full bg-app-primary border-transparent flex items-center justify-center text-white transition-all";
            document.getElementById('earnings-radio').innerHTML = '<i data-lucide="check" class="w-3 h-3"></i>';
            checkFunds();
            lucide.createIcons();
        }

        function selectBank() {
            if (amount < 1000) return;
            resetOptions();
            selectedMethod = 'bank';
            bankOption.classList.add('border-app-primary', 'ring-1', 'ring-app-primary');
            document.getElementById('bank-radio').className = "w-5 h-5 rounded-full bg-app-primary border-transparent flex items-center justify-center text-white transition-all";
            document.getElementById('bank-radio').innerHTML = '<i data-lucide="check" class="w-3 h-3"></i>';
            document.getElementById('bank-details-area').classList.remove('hidden');
            updatePayButtonState();
            lucide.createIcons();
        }

        function previewProof(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('upload-placeholder').classList.add('hidden');
                    const img = document.getElementById('proof-preview');
                    img.src = e.target.result;
                    img.classList.remove('hidden');
                    payBtn.disabled = false;
                    payBtn.classList.remove('bg-gray-200', 'dark:bg-gray-800', 'text-gray-400', 'dark:text-gray-500', 'cursor-not-allowed', 'shadow-none');
                    payBtn.classList.add('bg-green-600', 'dark:bg-green-700', 'text-white', 'shadow-lg');
                    payBtn.innerHTML = `Submit Payment`;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updatePayButtonState() {
            if (selectedMethod === 'bank') {
                const fileInput = document.getElementById('proof-upload');
                if (fileInput.files.length === 0) {
                    payBtn.disabled = true;
                    payBtn.classList.remove('bg-app-primary', 'text-white', 'shadow-lg');
                    payBtn.classList.add('bg-gray-200', 'dark:bg-gray-800', 'text-gray-400', 'dark:text-gray-500', 'cursor-not-allowed', 'shadow-none');
                    payBtn.innerHTML = 'Upload Proof to Continue';
                }
            }
        }

        function copyAccount() {
            const num = document.getElementById('account-number').innerText;
            navigator.clipboard.writeText(num);
            alert("Account number copied!");
        }

        function copyOrderId() {
            navigator.clipboard.writeText(currentOrderId);
            alert("Order ID Copied!");
        }

        async function processPayment() {
            const processingModal = document.getElementById('processing-modal');
            processingModal.classList.remove('hidden');

            const token = localStorage.getItem('token');
            const ENDPOINT = (typeof API_CONFIG !== 'undefined' && API_CONFIG.PAYMENT) ? API_CONFIG.PAYMENT : 'ajax-router.php?action=payment';

            const formData = new FormData();
            formData.append('amount', amount);
            formData.append('raffle_id', raffleId);
            formData.append('numbers', numbersRaw);

            // *** GOLDEN BOX FLAG ***
            const hasGoldenDiscount = urlParams.get('discount_applied') === 'true' ||
                                      (localStorage.getItem('rk_cart_session') && localStorage.getItem('rk_cart_session').includes('"discount_applied":true'));

            formData.append('is_golden_box', hasGoldenDiscount);

            if (selectedMethod === 'wallet') {
                formData.append('type', 'wallet_payment');
                try {
                    const response = await fetch(ENDPOINT, {
                        method: 'POST',
                        headers: { 'Authorization': `Bearer ${token}` },
                        body: formData
                    });
                    const result = await response.json();
                    processingModal.classList.add('hidden');

                    if (result.success) {
                        localStorage.setItem('walletBalance', result.new_balance);

                        // Clear cart session on successful payment
                        localStorage.removeItem('rk_cart_session');
                        localStorage.setItem('rk_last_purchase_time', Date.now());

                        showSuccess("Ticket Generated", "Paid via Wallet", "check");
                    } else {
                        alert("Payment Failed: " + (result.message || 'Unknown error'));
                    }
                } catch (err) {
                    processingModal.classList.add('hidden');
                    alert("Network Error: " + err.message);
                }
                return;
            }

            if (selectedMethod === 'earnings') {
                formData.append('type', 'earnings_payment');
                try {
                    const response = await fetch(ENDPOINT, {
                        method: 'POST',
                        headers: { 'Authorization': `Bearer ${token}` },
                        body: formData
                    });
                    const result = await response.json();
                    processingModal.classList.add('hidden');

                    if (result.success) {
                        localStorage.setItem('earningsBalance', result.new_balance);

                        // Clear cart session on successful payment
                        localStorage.removeItem('rk_cart_session');
                        localStorage.setItem('rk_last_purchase_time', Date.now());

                        showSuccess("Ticket Generated", "Paid via Winnings", "check");
                    } else {
                        alert("Payment Failed: " + (result.message || 'Unknown error'));
                    }
                } catch (err) {
                    processingModal.classList.add('hidden');
                    alert("Network Error: " + err.message);
                }
                return;
            }

            const fileInput = document.getElementById('proof-upload');
            if (fileInput.files.length === 0) return;

            formData.append('proof', fileInput.files[0]);
            formData.append('type', 'ticket_purchase');
            formData.append('order_id', currentOrderId);

            try {
                const response = await fetch(ENDPOINT, {
                    method: 'POST',
                    headers: { 'Authorization': `Bearer ${token}` },
                    body: formData
                });

                const result = await response.json();
                processingModal.classList.add('hidden');

                if (result.success) {
                    // Clear cart session on successful payment
                    localStorage.removeItem('rk_cart_session');
                    localStorage.setItem('rk_last_purchase_time', Date.now());

                    if(result.status === 'manual_review') {
                        showSuccess("Review Pending", "Receipt submitted. Admin will review.", "clock");
                    } else {
                        showSuccess("Verification Successful", "Receipt verified & Tickets Locked.", "check");
                    }
                } else {
                    alert("Error: " + result.message);
                }
            } catch (error) {
                processingModal.classList.add('hidden');
                alert("Network error: " + error.message);
            }
        }

        function showSuccess(title, msg, iconName = 'check') {
            const successModal = document.getElementById('success-modal');
            const successContent = document.getElementById('success-content');

            document.getElementById('success-title').innerText = title;
            document.getElementById('success-msg').innerText = msg;

            const iconElement = document.getElementById('success-icon');
            const iconBg = document.getElementById('icon-bg');
            const ring1 = document.getElementById('splash-ring-1');
            const ring2 = document.getElementById('splash-ring-2');

            if (iconName === 'clock') {
                iconBg.classList.remove('bg-green-100', 'dark:bg-green-900/30');
                iconBg.classList.add('bg-orange-100', 'dark:bg-orange-900/30');
                iconElement.classList.remove('text-green-600', 'dark:text-green-500');
                iconElement.classList.add('text-orange-600', 'dark:text-orange-500');
                ring1.classList.remove('bg-green-100', 'dark:bg-green-900/20');
                ring1.classList.add('bg-orange-100', 'dark:bg-orange-900/20');
                ring2.classList.remove('bg-green-50', 'dark:bg-green-900/10');
                ring2.classList.add('bg-orange-50', 'dark:bg-orange-900/10');
                iconElement.setAttribute('data-lucide', 'clock');
            } else {
                iconBg.classList.add('bg-green-100', 'dark:bg-green-900/30');
                iconBg.classList.remove('bg-orange-100', 'dark:bg-orange-900/30');
                iconElement.classList.add('text-green-600', 'dark:text-green-500');
                iconElement.classList.remove('text-orange-600', 'dark:text-orange-500');
                ring1.classList.add('bg-green-100', 'dark:bg-green-900/20');
                ring1.classList.remove('bg-orange-100', 'dark:bg-orange-900/20');
                ring2.classList.add('bg-green-5', 'dark:bg-green-900/10');
                ring2.classList.remove('bg-orange-50', 'dark:bg-orange-900/10');
                iconElement.setAttribute('data-lucide', 'check');
            }

            const upsellBox = document.getElementById('upsell-container');
            const upsellBadge = document.getElementById('upsell-badge');
            const upsellHead = document.getElementById('upsell-headline');
            const upsellBody = document.getElementById('upsell-body');
            const upsellBtn = document.getElementById('upsell-cta');

            if (amount < 1000) {
                upsellBox.classList.remove('hidden');
                upsellBadge.className = "absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest shadow-sm bg-yellow-400 text-black";
                upsellBadge.innerText = "Double Your Luck";
                upsellHead.innerText = "Don't Miss The Millions! 💎";
                upsellBody.innerText = "You have the Daily Ticket. But the Weekly Jackpot is massive. Secure your spot now!";
                upsellBtn.className = "w-full py-2.5 rounded-xl font-bold text-xs shadow-lg flex items-center justify-center gap-2 active:scale-95 transition-transform bg-gradient-to-r from-purple-600 to-indigo-600 text-white shadow-purple-500/30";
                upsellBtn.innerHTML = `Get Weekly Ticket <i data-lucide="star" class="w-3 h-3"></i>`;
                upsellBtn.href = weeklyRaffleId ? `raffle-details.php?id=${weeklyRaffleId}` : "raffles.php";
            } else {
                upsellBox.classList.remove('hidden');
                upsellBadge.className = "absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest shadow-sm bg-red-500 text-white";
                upsellBadge.innerText = "Happening Today";
                upsellHead.innerText = "Want to Win Tonight? ⚡";
                upsellBody.innerText = "The Weekly draw is far away. Play the Daily Draw for instant results tonight!";
                upsellBtn.className = "w-full py-2.5 rounded-xl font-bold text-xs shadow-lg flex items-center justify-center gap-2 active:scale-95 transition-transform bg-gradient-to-r from-red-500 to-orange-500 text-white shadow-orange-500/30";
                upsellBtn.innerHTML = `Play Daily Draw <i data-lucide="zap" class="w-3 h-3"></i>`;
                upsellBtn.href = dailyRaffleId ? `raffle-details.php?id=${dailyRaffleId}` : "raffles.php";
            }

            lucide.createIcons();

            successModal.classList.remove('hidden');
            setTimeout(() => {
                successContent.classList.remove('scale-90', 'opacity-0');
                successContent.classList.add('scale-100', 'opacity-100');
            }, 50);
        }

        let exitModalShown = false;
        let exitIntentInitialized = false;
        let historyTrapSet = false;

        function initExitIntent() {
            if (exitIntentInitialized) return;
            exitIntentInitialized = true;

            document.addEventListener('mouseleave', (e) => {
                if (e.clientY <= 0 && !exitModalShown) showExitModal();
            });

            function setHistoryTrap() {
                if (historyTrapSet) return;
                historyTrapSet = true;
                const state = { trap: true };
                window.history.pushState(state, "", window.location.href);
            }

            const events = ['touchstart', 'click', 'scroll', 'mousemove'];
            function onUserInteract() {
                setHistoryTrap();
                events.forEach(e => document.removeEventListener(e, onUserInteract));
            }
            events.forEach(e => document.addEventListener(e, onUserInteract, { passive: true }));

            window.addEventListener('popstate', (event) => {
                if (!exitModalShown) {
                    showExitModal();
                    setTimeout(() => {
                        window.history.pushState({ trap: true }, "", window.location.href);
                    }, 0);
                }
            });

            let touchStartY = 0;
            let touchStartX = 0;

            document.addEventListener('touchstart', (e) => {
                touchStartY = e.touches[0].clientY;
                touchStartX = e.touches[0].clientX;
            }, { passive: true });

            document.addEventListener('touchmove', (e) => {
                const touchY = e.touches[0].clientY;
                const touchX = e.touches[0].clientX;
                const deltaY = touchY - touchStartY;
                const deltaX = touchX - touchStartX;

                if (touchStartX < 50 && deltaX > 100 && Math.abs(deltaY) < 50 && !exitModalShown) {
                    showExitModal();
                }
            }, { passive: true });
        }

        function showExitModal() {
            if (!document.getElementById('success-modal').classList.contains('hidden')) return;
            if (exitModalShown || sessionStorage.getItem('rk_exit_shown')) return;

            exitModalShown = true;
            sessionStorage.setItem('rk_exit_shown', 'true');

            const container = document.getElementById('exit-tickets');
            if (numbers.length > 0) {
                container.innerHTML = '';
                numbers.slice(0, 3).forEach((num, i) => {
                    const rot = (i === 1) ? 'rotate-[-5deg]' : (i===2 ? 'rotate-[5deg]' : 'rotate-0');
                    container.innerHTML += `
                        <div class="w-12 h-16 bg-white border-2 border-gray-200 rounded-lg flex flex-col items-center justify-center shadow-md transform ${rot} z-${10-i}">
                            <span class="text-[8px] text-gray-400 uppercase">TICKET</span>
                            <span class="text-sm font-black text-gray-800">${num}</span>
                        </div>
                    `;
                });
            }

            const modal = document.getElementById('exit-intent-modal');
            modal.classList.remove('hidden', 'pointer-events-none');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modal.querySelector('div.transform').classList.remove('scale-95');
            }, 10);
        }

        function closeExitModal(forceLeave = false) {
            const modal = document.getElementById('exit-intent-modal');
            modal.classList.add('opacity-0');
            modal.querySelector('div.transform').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden', 'pointer-events-none');
            }, 300);

            if (forceLeave) {
                sessionStorage.removeItem('rk_exit_shown');
                window.history.back();
                setTimeout(() => { window.location.href = 'raffles.php'; }, 100);
            }
        }

        function triggerExitIntent() {
            showExitModal();
        }

        // Add exit handler to preserve cart on navigation
        window.addEventListener('beforeunload', function() {
            // If user is leaving without paying, keep cart in localStorage
            // The Golden Box will trigger on raffles.php
            console.log('Cart preserved for recovery');
        });
    </script>
</body>
</html>
