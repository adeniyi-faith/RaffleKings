<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 relative">

    <!-- Header -->
    <div class="bg-white px-5 pt-2 pb-4 border-b border-gray-100 sticky top-0 z-40 shadow-sm">
        <h2 class="text-xl font-bold text-gray-900">Weekly Cash Wins</h2>
        <p class="text-xs text-gray-500">The Friday Payday. Guaranteed Winners.</p>
    </div>

    <!-- 1. The Progressive Jackpot Hero -->
    <section class="p-5 pb-2">
        <div class="bg-gradient-to-br from-green-600 to-emerald-800 rounded-3xl p-6 text-white shadow-xl shadow-green-900/20 relative overflow-hidden">
            <!-- Background effects -->
            <div class="absolute top-0 right-0 w-40 h-40 bg-white/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
            <div class="absolute bottom-0 left-0 w-32 h-32 bg-black/10 rounded-full blur-2xl translate-y-1/2 -translate-x-1/2"></div>
            
            <div class="relative z-10 text-center">
                <div class="inline-flex items-center gap-1.5 bg-green-900/30 backdrop-blur-sm px-3 py-1 rounded-full text-[10px] font-bold text-green-100 mb-4 border border-green-500/30 animate-pulse">
                    <span class="w-2 h-2 bg-green-400 rounded-full"></span>
                    LIVE POT
                </div>

                <p class="text-sm text-green-100 font-medium mb-1">Current Grand Prize</p>
                <!-- Animated Counter -->
                <h1 id="jackpot-display" class="text-4xl font-bold tracking-tight mb-2 font-mono">₦1,245,500</h1>
                
                <div class="flex items-center justify-center gap-2 text-xs text-green-200/80 mb-6">
                    <i data-lucide="shield-check" class="w-3 h-3"></i>
                    <span>Guaranteed Min: ₦100,000</span>
                </div>

                <!-- Countdown -->
                <div class="bg-white/10 backdrop-blur-md rounded-xl p-3 border border-white/10 flex justify-between items-center mb-5">
                    <div class="text-left">
                        <p class="text-[10px] text-green-200">Draw Closes In:</p>
                        <p class="font-mono font-bold text-lg">04<span class="text-xs">h</span> 12<span class="text-xs">m</span> 30<span class="text-xs">s</span></p>
                    </div>
                    <div class="h-8 w-px bg-white/20"></div>
                    <div class="text-right">
                        <p class="text-[10px] text-green-200">Ticket Price</p>
                        <p class="font-bold text-lg">₦1,000</p>
                    </div>
                </div>

                <button onclick="openCashSheet()" class="w-full bg-white text-green-800 py-3.5 rounded-xl font-bold shadow-lg shadow-green-900/20 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
                    Play for ₦1,000 <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    </section>

    <!-- 2. Daily Drops (Retention Strategy) -->
    <section class="px-5 py-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider">Daily Drops</h3>
            <span class="text-[10px] text-app-primary bg-blue-50 px-2 py-1 rounded font-medium">Mon - Thu</span>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 p-4 shadow-sm flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center text-orange-600">
                    <i data-lucide="zap" class="w-5 h-5"></i>
                </div>
                <div>
                    <h4 class="font-bold text-gray-900 text-sm">Instant ₦5,000</h4>
                    <p class="text-xs text-gray-500">5 Winners selected daily</p>
                </div>
            </div>
            <button class="px-4 py-2 bg-gray-50 text-gray-600 text-xs font-bold rounded-lg border border-gray-200 active:bg-gray-100">
                Auto-Entry
            </button>
        </div>
        <p class="text-[10px] text-gray-400 mt-2 px-1">
            *Every Friday Jackpot ticket gets you automatic entry into Daily Drops for the week.
        </p>
    </section>

    <!-- 3. Transparency & Stats -->
    <section class="px-5 pb-6">
        <div class="grid grid-cols-2 gap-3 mb-4">
            <div class="bg-white p-3 rounded-xl border border-gray-100 text-center">
                <p class="text-[10px] text-gray-400 uppercase">Participants</p>
                <p class="text-lg font-bold text-gray-800">1,245</p>
            </div>
            <div class="bg-white p-3 rounded-xl border border-gray-100 text-center">
                <p class="text-[10px] text-gray-400 uppercase">Last Winner</p>
                <p class="text-lg font-bold text-gray-800">₦1.1M</p>
            </div>
        </div>

        <!-- The "Constitution" Rule Card -->
        <div class="bg-blue-50 rounded-xl p-4 border border-blue-100">
            <h4 class="text-xs font-bold text-blue-800 mb-2 flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4"></i> How the Pot Works
            </h4>
            <p class="text-xs text-blue-700 leading-relaxed">
                We guarantee a minimum of <strong>₦100,000</strong>. After that, <strong>50%</strong> of every single ticket sold is added directly to the pot. The more people play, the bigger you win.
            </p>
        </div>
    </section>

</div>

<!-- Purchase Bottom Sheet -->
<div id="cash-overlay" onclick="closeCashSheet()" class="fixed inset-0 bg-black/60 z-50 hidden transition-opacity opacity-0 backdrop-blur-sm"></div>

<div id="cash-sheet" class="fixed bottom-0 left-0 w-full bg-white rounded-t-3xl z-50 transform translate-y-full transition-transform duration-300 ease-out sm:max-w-md sm:left-1/2 sm:-translate-x-1/2 safe-bottom shadow-2xl">
    
    <!-- Handle -->
    <div class="w-full flex justify-center pt-3 pb-1" onclick="closeCashSheet()">
        <div class="w-12 h-1.5 bg-gray-200 rounded-full"></div>
    </div>

    <div class="p-6 pt-2">
        <div class="flex justify-between items-center mb-6">
            <div>
                <p class="text-xs text-gray-400 font-medium">Entering Draw</p>
                <h3 class="text-xl font-bold text-gray-900">Friday Cash Blowout</h3>
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-400 font-medium">Price</p>
                <p class="text-xl font-bold text-green-600">₦1,000</p>
            </div>
        </div>

        <!-- Quantity Selector -->
        <div class="flex items-center justify-between bg-gray-50 rounded-xl p-2 mb-4 border border-gray-100">
            <button onclick="updateCashQuantity(-1)" class="w-10 h-10 bg-white rounded-lg shadow-sm border border-gray-200 flex items-center justify-center text-gray-600 active:scale-90 transition-transform">
                <i data-lucide="minus" class="w-4 h-4"></i>
            </button>
            <div class="text-center">
                <span id="cash-ticket-count" class="text-xl font-bold text-gray-800 block">1</span>
                <span class="text-[10px] text-gray-400">Ticket(s)</span>
            </div>
            <button onclick="updateCashQuantity(1)" class="w-10 h-10 bg-app-primary rounded-lg shadow-sm shadow-blue-200 flex items-center justify-center text-white active:scale-90 transition-transform">
                <i data-lucide="plus" class="w-4 h-4"></i>
            </button>
        </div>

        <!-- Bundle Deal Alert -->
        <div id="cash-bundle-alert" class="hidden bg-green-50 text-green-700 text-xs font-medium p-3 rounded-lg mb-6 flex items-center gap-2 border border-green-100">
            <i data-lucide="gift" class="w-4 h-4"></i>
            <span>Bundle unlocked: <strong>+1 Free Ticket</strong> added!</span>
        </div>

        <!-- Total -->
        <div class="flex items-center justify-between mb-4 pt-2 border-t border-gray-50">
            <span class="text-sm text-gray-500">Total to pay:</span>
            <span id="cash-total-price" class="text-2xl font-bold text-gray-900">₦1,000</span>
        </div>

        <button class="w-full bg-app-primary text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
            Confirm & Pay <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </button>
        
        <p class="text-center text-[10px] text-gray-400 mt-3">Wallet Balance: ₦2,500</p>
    </div>
</div>

<script>
    // Progressive Pot Simulation
    const jackpotDisplay = document.getElementById('jackpot-display');
    let currentPot = 1245500;
    
    // Simulate someone buying a ticket every few seconds
    setInterval(() => {
        // Add ₦500 (50% of 1000 ticket)
        currentPot += 500; 
        jackpotDisplay.innerText = '₦' + currentPot.toLocaleString();
        
        // Add a subtle pulse animation on update
        jackpotDisplay.classList.add('text-green-300');
        setTimeout(() => jackpotDisplay.classList.remove('text-green-300'), 300);
    }, 5000); // Updates every 5 seconds

    // Bottom Sheet Logic
    let cashQuantity = 1;
    const ticketPrice = 1000;
    
    const cashOverlay = document.getElementById('cash-overlay');
    const cashSheet = document.getElementById('cash-sheet');
    const cashCountDisplay = document.getElementById('cash-ticket-count');
    const cashTotalDisplay = document.getElementById('cash-total-price');
    const cashBundleAlert = document.getElementById('cash-bundle-alert');

    function openCashSheet() {
        cashOverlay.classList.remove('hidden');
        setTimeout(() => {
            cashOverlay.classList.remove('opacity-0');
            cashSheet.classList.remove('translate-y-full');
            if(window.innerWidth >= 640) {
                 cashSheet.classList.remove('sm:translate-y-[120%]');
            }
        }, 10);
    }

    function closeCashSheet() {
        cashOverlay.classList.add('opacity-0');
        cashSheet.classList.add('translate-y-full');
        setTimeout(() => {
            cashOverlay.classList.add('hidden');
        }, 300);
    }

    function updateCashQuantity(change) {
        if (cashQuantity + change >= 1) {
            cashQuantity += change;
            
            cashCountDisplay.innerText = cashQuantity;
            cashTotalDisplay.innerText = '₦' + (cashQuantity * ticketPrice).toLocaleString();

            if (cashQuantity >= 5) {
                cashBundleAlert.classList.remove('hidden');
            } else {
                cashBundleAlert.classList.add('hidden');
            }
        }
    }
</script>

<?php include 'footer.php'; ?>