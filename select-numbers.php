<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Pick Your Numbers - RaffleKings</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Config (Inlined for Preview) -->
    <script>
        const APP_SETTINGS = {
            CURRENCY_SYMBOL: '₦',
        };

        // *** SMART THEME LOGIC (System Preference + Manual Override) ***
        (function() {
            const localTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            // Logic: If manual is 'dark', OR manual is null/undefined AND system is dark
            if (localTheme === 'dark' || (!localTheme && systemDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();

        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        app: { primary: '#2563EB', primaryDark: '#1d4ed8' },
                        'dark-bg': '#0f172a',
                        'dark-card': '#1e293b',
                        'dark-border': '#334155'
                    }
                }
            }
        }
    </script>

    <style>
        body { font-family: 'Inter', sans-serif; overscroll-behavior-y: none; }
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Taken Number Style */
        .taken-number {
            background-color: #FEF2F2 !important; /* red-50 */
            color: #EF4444 !important; /* red-500 */
            border-color: #FECACA !important; /* red-200 */
            cursor: not-allowed !important;
            position: relative;
            overflow: hidden;
        }

        /* Dark mode overrides for taken numbers */
        .dark .taken-number {
            background-color: #450a0a !important; /* red-950 */
            color: #f87171 !important; /* red-400 */
            border-color: #7f1d1d !important; /* red-900 */
        }

        .taken-number::after {
            content: 'SOLD';
            position: absolute;
            bottom: 2px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 8px;
            font-weight: 800;
            color: #FCA5A5; /* red-300 */
            letter-spacing: 0.5px;
        }

        .dark .taken-number::after {
            color: #ef4444; /* red-500 */
        }
    </style>
</head>
<body class="flex flex-col h-[100dvh] bg-white dark:bg-dark-bg text-gray-800 dark:text-white transition-colors duration-200">

    <!-- Compact Sticky Header -->
    <div class="px-4 py-3 flex justify-between items-center sticky top-0 z-20 bg-white/95 dark:bg-dark-bg/95 backdrop-blur-sm border-b border-gray-100 dark:border-dark-border safe-top transition-colors duration-200">
        <div class="flex items-center gap-3">
            <button onclick="history.back()" aria-label="Go back" class="text-gray-400 hover:text-gray-600 dark:hover:text-white active:scale-95 transition-transform p-1 -ml-1">
                <i data-lucide="arrow-left" class="w-6 h-6"></i>
            </button>
            <div>
                <h1 class="text-sm font-bold text-gray-900 dark:text-white leading-none" id="raffle-name">Select Numbers</h1>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 font-medium mt-0.5">
                    Pick <span id="qty-target" class="text-gray-900 dark:text-white font-bold">...</span> numbers
                </p>
            </div>
        </div>

        <div class="flex flex-col items-end gap-1">
            <button onclick="quickPick()" class="text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-50 dark:bg-dark-card border border-gray-200 dark:border-dark-border px-3 py-2 rounded-lg shadow-sm flex items-center gap-1.5 active:scale-95 transition-transform hover:bg-gray-100 dark:hover:bg-gray-800">
                <i data-lucide="shuffle" class="w-3.5 h-3.5 text-yellow-500"></i> Quick Pick
            </button>
            <!-- UNSELECT BUTTON -->
            <button onclick="clearSelection()" id="unselect-btn" class="text-[10px] font-semibold text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 flex items-center gap-1 opacity-0 pointer-events-none transition-opacity">
                <i data-lucide="x" class="w-3 h-3"></i> Unselect All
            </button>
        </div>
    </div>

    <!-- Scrollable Number Grid -->
    <div class="flex-1 overflow-y-auto p-1 no-scrollbar bg-white dark:bg-dark-bg relative transition-colors duration-200">
        <!-- Legend -->
        <div class="flex justify-center gap-4 py-2 border-b border-gray-50 dark:border-dark-border mb-2">
            <div class="flex items-center gap-1">
                <div class="w-3 h-3 rounded bg-white dark:bg-dark-card border border-gray-300 dark:border-gray-600"></div>
                <span class="text-[10px] text-gray-400 dark:text-gray-500 font-medium">Available</span>
            </div>
            <div class="flex items-center gap-1">
                <div class="w-3 h-3 rounded bg-yellow-400 border border-yellow-500"></div>
                <span class="text-[10px] text-gray-400 dark:text-gray-500 font-medium">Yours</span>
            </div>
            <div class="flex items-center gap-1">
                <div class="w-3 h-3 rounded bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-900 relative overflow-hidden">
                    <div class="absolute inset-0 flex items-center justify-center text-[6px] text-red-400 font-bold">X</div>
                </div>
                <span class="text-[10px] text-gray-400 dark:text-gray-500 font-medium">Sold Out</span>
            </div>
        </div>

        <div id="loading-skeleton" class="grid grid-cols-5 gap-2 p-3 max-w-lg mx-auto animate-pulse">
            <script>
                for(let i=0; i<50; i++) {
                    document.write(`<div class="h-12 w-full rounded-xl bg-gray-100 dark:bg-dark-card border border-gray-200 dark:border-dark-border"></div>`);
                }
            </script>
        </div>

        <div class="grid grid-cols-5 gap-2 p-3 pb-32 max-w-lg mx-auto hidden" id="number-grid"></div>
    </div>

    <!-- Floating Footer Action -->
    <div id="action-bar" class="hidden absolute bottom-6 left-0 w-full px-5 z-30 safe-bottom">
        <div class="bg-gray-900 dark:bg-dark-card text-white p-4 rounded-2xl shadow-2xl flex items-center gap-4 fade-in-up border border-gray-800 dark:border-dark-border">
            <div class="flex-1">
                <p class="text-[10px] text-gray-400 dark:text-gray-500 uppercase font-bold tracking-wide">Total Pay</p>
                <p class="text-xl font-bold text-white leading-none" id="footer-total">₦0</p>
            </div>
            <button onclick="confirmSelection()" id="confirm-btn" class="bg-white text-gray-900 dark:bg-blue-600 dark:text-white px-6 py-3 rounded-xl font-bold text-sm shadow-lg active:scale-95 transition-transform flex items-center gap-2">
                Checkout Now <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </button>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="fixed top-20 left-1/2 -translate-x-1/2 bg-gray-800 dark:bg-white text-white dark:text-gray-900 text-xs font-bold px-4 py-2 rounded-full shadow-lg z-50 transition-opacity opacity-0 pointer-events-none transform -translate-y-2">
        <span id="toast-msg">Message</span>
    </div>

    <!-- *** FUND WALLET MODAL (The Trap) *** -->
    <div id="fund-modal" class="fixed inset-0 bg-black/90 z-[999] hidden flex items-center justify-center backdrop-blur-sm p-5 opacity-0 transition-opacity duration-300">
        <div class="bg-white dark:bg-dark-card rounded-3xl p-6 w-full max-w-sm text-center relative overflow-hidden transform scale-95 transition-transform duration-300" id="fund-modal-content">
            <div class="absolute top-0 left-0 w-full h-1 bg-red-500"></div>
            <div class="w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4 animate-bounce">
                <i data-lucide="wallet" class="w-8 h-8 text-red-600"></i>
            </div>
            <h2 class="text-2xl font-black text-gray-900 dark:text-white mb-2">Low Balance!</h2>
            <p class="text-gray-500 dark:text-gray-400 text-sm mb-6 leading-relaxed">
                You need <span id="needed-amt" class="font-bold text-gray-900 dark:text-white">...</span> to play.<br>
                Don't miss this draw!
            </p>

            <div class="bg-gray-50 dark:bg-dark-bg p-3 rounded-xl mb-6 flex justify-between items-center">
                <span class="text-xs text-gray-500">Your Wallet:</span>
                <span class="font-bold text-gray-900 dark:text-white" id="current-bal">₦0.00</span>
            </div>

            <button onclick="window.location.href='topup.php'" class="w-full bg-green-600 text-white py-3.5 rounded-xl font-bold text-sm shadow-lg shadow-green-500/30 active:scale-95 transition-transform mb-3">
                Fund Wallet Now
            </button>
            <button onclick="history.back()" class="text-xs text-gray-400 font-medium hover:text-gray-600 dark:hover:text-gray-300">Go Back</button>
        </div>
    </div>

    <script>
        let selection = null;
        let selectedNumbers = [];
        let targetQty = 0;
        let maxPool = 1000;
        let takenNumbers = [];

        document.addEventListener('DOMContentLoaded', async () => {
            lucide.createIcons();

            try {
                const stored = localStorage.getItem('currentRaffleSelection');
                if (!stored) {
                    // MOCK DATA FOR PREVIEW IF NONE EXISTS
                    selection = {
                        raffleId: 101,
                        raffleTitle: "iPhone 15 Pro Max",
                        qty: 3,
                        maxPool: 200,
                        totalPrice: 4500
                    };
                    console.log("Using Mock Data for Preview");
                } else {
                    selection = JSON.parse(stored);
                }
            } catch (e) {
                console.error(e);
                window.location.href = 'raffles.php';
                return;
            }

            targetQty = parseInt(selection.qty) || 1;
            maxPool = parseInt(selection.maxPool) || 1000;

            document.getElementById('raffle-name').innerText = selection.raffleTitle || 'Raffle';
            document.getElementById('qty-target').innerText = targetQty;
            document.getElementById('footer-total').innerText = '₦' + (parseFloat(selection.totalPrice) || 0).toLocaleString();

            // *** CRITICAL: WALLET CHECK LOGIC ***
            checkWalletStatus();

            await fetchTakenNumbers(selection.raffleId);

            // --- PERSISTENCE: Restore previous selection if page refreshed ---
            const pending = localStorage.getItem('pendingCheckout');
            if (pending) {
                try {
                    const pData = JSON.parse(pending);
                    if (pData.raffle_id == selection.raffleId && pData.qty == targetQty && pData.numbers) {
                        const savedNums = pData.numbers.split(',').map(Number);
                        selectedNumbers = savedNums.filter(n => !takenNumbers.includes(n));

                        if(selectedNumbers.length < savedNums.length) {
                             showToast("Some of your numbers were just sold!");
                        }
                    }
                } catch(e) { console.error("Restore failed", e); }
            }

            generateGrid();
            updateState();
        });

        // *** THE TRAP LOGIC ***
        function checkWalletStatus() {
            // Only checks if token exists (User logged in)
            const token = localStorage.getItem('token');
            // Note: If no token, we assume guest and let them pick, but Checkout will redirect to Register
            if (!token) return;

            const wallet = parseFloat(localStorage.getItem('walletBalance')) || 0;
            const earnings = parseFloat(localStorage.getItem('earningsBalance')) || 0;
            const totalAvailable = wallet + earnings;
            const required = parseFloat(selection.totalPrice) || 0;
            const qty = parseInt(selection.qty) || 1;

            // Calculate unit price to check for micro-raffles
            // We use pricePerTicket if available, otherwise derive it
            const unitPrice = selection.pricePerTicket || (required / qty);

            // LOGIC: Only trap if (Balance < Required) AND (Ticket Price < 1000)
            if (totalAvailable < required && unitPrice < 1000) {
                // User is broke. TRAP THEM.
                document.getElementById('needed-amt').innerText = '₦' + required.toLocaleString();
                document.getElementById('current-bal').innerText = '₦' + totalAvailable.toLocaleString();

                const modal = document.getElementById('fund-modal');
                const content = document.getElementById('fund-modal-content');

                modal.classList.remove('hidden');
                // Animation frame
                setTimeout(() => {
                    modal.classList.remove('opacity-0');
                    content.classList.remove('scale-95');
                    content.classList.add('scale-100');
                }, 10);
            }
        }

        async function fetchTakenNumbers(raffleId) {
            try {
                // *** REAL FETCH FIRST ***
                const res = await fetch(`ajax-router.php?action=get_raffle&id=${encodeURIComponent(raffleId)}`);
                if(res.ok) {
                    const data = await res.json();
                    if(data.raffle_meta && data.raffle_meta.taken_numbers) {
                        takenNumbers = data.raffle_meta.taken_numbers.map(Number);
                        return; // Success!
                    }
                }
            } catch(e) {
                console.warn("Real fetch failed or unavailable, checking fallback...", e);
            }

            // *** FALLBACK ***
            if (takenNumbers.length === 0) {
                 console.log("Using preview mock for taken numbers");
                 takenNumbers = [5, 12, 45, 88, 102];
            }
        }

        function generateGrid() {
            const grid = document.getElementById('number-grid');
            const skeleton = document.getElementById('loading-skeleton');
            let html = '';

            // Button styles
            const baseClass = "h-12 w-full rounded-xl bg-gray-50 dark:bg-dark-card border border-gray-200 dark:border-dark-border text-sm font-bold text-gray-500 dark:text-gray-400 flex items-center justify-center transition-all active:scale-90 hover:bg-gray-100 dark:hover:bg-gray-800 select-none relative";
            const selectedClass = "h-12 w-full rounded-xl bg-yellow-400 border-yellow-500 text-gray-900 text-lg font-bold flex items-center justify-center shadow-lg shadow-yellow-200/50 transform scale-105 transition-all ring-2 ring-offset-1 ring-yellow-400 select-none relative";

            for (let i = 1; i <= maxPool; i++) {
                const isTaken = takenNumbers.includes(i);
                const isSelected = selectedNumbers.includes(i);
                const takenClass = isTaken ? 'taken-number' : '';
                const initialClass = isSelected ? selectedClass : (baseClass + ' ' + takenClass);
                const clickAction = isTaken ? `showToast('Number ${i} is already sold!')` : `toggleNumber(${i}, this)`;

                html += `<button onclick="${clickAction}" id="btn-${i}" class="${initialClass}">${i}</button>`;
            }

            grid.innerHTML = html;
            skeleton.classList.add('hidden');
            grid.classList.remove('hidden');
        }

        function toggleNumber(num, btn) {
            if (navigator.vibrate) navigator.vibrate(5);

            const baseClass = "h-12 w-full rounded-xl bg-gray-50 dark:bg-dark-card border border-gray-200 dark:border-dark-border text-sm font-bold text-gray-500 dark:text-gray-400 flex items-center justify-center transition-all active:scale-90 hover:bg-gray-100 dark:hover:bg-gray-800 select-none relative";
            const selectedClass = "h-12 w-full rounded-xl bg-yellow-400 border-yellow-500 text-gray-900 text-lg font-bold flex items-center justify-center shadow-lg shadow-yellow-200/50 transform scale-105 transition-all ring-2 ring-offset-1 ring-yellow-400 select-none relative";

            if (selectedNumbers.includes(num)) {
                selectedNumbers = selectedNumbers.filter(n => n !== num);
                btn.className = baseClass;
            } else {
                if (selectedNumbers.length < targetQty) {
                    selectedNumbers.push(num);
                    btn.className = selectedClass;
                } else {
                    btn.classList.add('animate-pulse', 'bg-red-50', 'border-red-200');
                    setTimeout(() => btn.classList.remove('animate-pulse', 'bg-red-50', 'border-red-200'), 300);
                    showToast(`You only paid for ${targetQty} numbers!`);
                    return;
                }
            }
            updateState();
        }

        function quickPick() {
            if (navigator.vibrate) navigator.vibrate(10);

            const selectedClass = "h-12 w-full rounded-xl bg-yellow-400 border-yellow-500 text-gray-900 text-lg font-bold flex items-center justify-center shadow-lg shadow-yellow-200/50 transform scale-105 transition-all ring-2 ring-offset-1 ring-yellow-400 select-none relative";

            const needed = targetQty - selectedNumbers.length;
            if (needed === 0) { clearSelection(); }

            const availablePool = [];
            for(let i=1; i<=maxPool; i++) {
                if(!takenNumbers.includes(i) && !selectedNumbers.includes(i)) {
                    availablePool.push(i);
                }
            }

            // Shuffle
            for (let i = availablePool.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [availablePool[i], availablePool[j]] = [availablePool[j], availablePool[i]];
            }

            const toAdd = targetQty - selectedNumbers.length;
            if(availablePool.length < toAdd) { showToast("Not enough tickets left!"); return; }

            const newPicks = availablePool.slice(0, toAdd);
            selectedNumbers = [...selectedNumbers, ...newPicks];

            selectedNumbers.forEach(num => {
                const btn = document.getElementById(`btn-${num}`);
                if(btn) btn.className = selectedClass;
            });

            updateState();
        }

        function clearSelection() {
            if (navigator.vibrate) navigator.vibrate(5);
            const baseClass = "h-12 w-full rounded-xl bg-gray-50 dark:bg-dark-card border border-gray-200 dark:border-dark-border text-sm font-bold text-gray-500 dark:text-gray-400 flex items-center justify-center transition-all active:scale-90 hover:bg-gray-100 dark:hover:bg-gray-800 select-none relative";

            selectedNumbers.forEach(num => {
                const btn = document.getElementById(`btn-${num}`);
                if(btn && !takenNumbers.includes(num)) btn.className = baseClass;
            });

            selectedNumbers = [];
            updateState();
            showToast("Selection cleared");
        }

        function updateState() {
            const count = selectedNumbers.length;
            const remaining = targetQty - count;
            const actionBar = document.getElementById('action-bar');
            const unselectBtn = document.getElementById('unselect-btn');

            if (count > 0) unselectBtn.classList.remove('opacity-0', 'pointer-events-none');
            else unselectBtn.classList.add('opacity-0', 'pointer-events-none');

            if (remaining === 0) {
                actionBar.classList.remove('hidden');
                lucide.createIcons();
            } else {
                actionBar.classList.add('hidden');
            }
        }

        function showToast(msg) {
            const toast = document.getElementById('toast');
            document.getElementById('toast-msg').innerText = msg;
            toast.classList.remove('opacity-0', 'translate-y-2');
            setTimeout(() => { toast.classList.add('opacity-0', 'translate-y-2'); }, 2000);
        }

        function confirmSelection() {
            if (selectedNumbers.length < targetQty) {
                showToast(`Please pick ${targetQty} numbers!`);
                return;
            }

            const btn = document.getElementById('confirm-btn');
            btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Processing...';
            btn.disabled = true;
            lucide.createIcons();

            const numbersStr = selectedNumbers.join(',');
            const isLoggedIn = localStorage.getItem('token');

            const checkoutData = {
                amount: selection.totalPrice,
                tickets: targetQty,
                numbers: numbersStr,
                raffle_id: selection.raffleId,
                raffleId: selection.raffleId,
                price: selection.totalPrice,
                qty: targetQty
            };
            localStorage.setItem('pendingCheckout', JSON.stringify(checkoutData));

            setTimeout(() => {
                if (isLoggedIn) {
                    window.location.href = `checkout.php?amount=${selection.totalPrice}&tickets=${targetQty}&numbers=${numbersStr}&raffle_id=${selection.raffleId}`;
                } else {
                    window.location.href = `register-special.php?amount=${selection.totalPrice}&tickets=${targetQty}&numbers=${numbersStr}&raffle_id=${selection.raffleId}`;
                }
            }, 500);
        }
    </script>
</body>
</html>
