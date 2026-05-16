<?php include 'header.php'; ?>

<!-- Auth Guard -->
<script>
    if (!localStorage.getItem('token')) {
        window.location.href = 'login';
    }
</script>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-40 bg-gray-50 relative">

    <!-- 1. Urgency Header (Sticky) -->
    <div class="bg-red-500 text-white px-5 py-2 sticky top-0 z-50 flex justify-between items-center shadow-md">
        <div class="flex items-center gap-2">
            <i data-lucide="clock" class="w-4 h-4 animate-pulse"></i>
            <span class="text-xs font-bold uppercase tracking-wide">Reservation Expires in:</span>
        </div>
        <span class="font-mono font-bold text-sm" id="cart-timer">15:00</span>
    </div>

    <!-- 2. Page Header -->
    <div class="bg-white px-5 pt-4 pb-4 border-b border-gray-100 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button onclick="history.back()" class="p-1 -ml-1 text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </button>
            <h2 class="text-lg font-bold text-gray-900">Reserved Tickets</h2>
        </div>
        <span class="bg-blue-50 text-app-primary text-[10px] font-bold px-2 py-1 rounded-lg" id="cart-count">0 Items</span>
    </div>

    <!-- 3. Dynamic Cart Items -->
    <div class="px-5 pt-6 space-y-4" id="cart-items-container">
        <!-- Will be filled by JS -->
    </div>

    <!-- Empty State (Hidden by default) -->
    <div id="empty-state" class="hidden flex flex-col items-center justify-center pt-20 text-center">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center text-gray-400 mb-4">
            <i data-lucide="shopping-cart" class="w-8 h-8"></i>
        </div>
        <h3 class="text-gray-900 font-bold mb-1">Your cart is empty</h3>
        <p class="text-gray-500 text-sm mb-6 max-w-xs mx-auto">Looks like you haven't selected any tickets yet.</p>
        <a href="raffles.php" class="bg-app-primary text-white px-6 py-3 rounded-xl font-bold text-sm shadow-lg shadow-blue-500/30">
            Start Playing
        </a>
    </div>

    <!-- Psychological Upsell (Visible only if cart has items) -->
    <div id="upsell-container" class="hidden px-5 pt-4">
        <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-3 flex gap-3 items-center">
            <div class="w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center text-yellow-600 flex-shrink-0">
                <i data-lucide="ticket" class="w-4 h-4"></i>
            </div>
            <div class="flex-1">
                <p class="text-xs text-yellow-800">
                    Add <strong>1 more ticket</strong> to unlock a <span class="font-bold">Bonus Entry!</span>
                </p>
            </div>
            <a href="raffles.php" class="text-xs font-bold text-app-primary">Add</a>
        </div>
    </div>

</div>

<!-- Sticky Checkout Footer -->
<div id="checkout-footer" class="fixed bottom-0 left-0 w-full bg-white border-t border-gray-100 p-5 safe-bottom z-50 shadow-[0_-5px_20px_rgba(0,0,0,0.05)] hidden">
    <div class="flex justify-between items-end mb-4">
        <p class="text-sm text-gray-500">Total to Pay</p>
        <div class="text-right">
            <span class="text-2xl font-bold text-gray-900" id="total-amount">₦0</span>
        </div>
    </div>

    <button onclick="goToCheckout()" class="w-full bg-app-primary text-white py-4 rounded-xl font-bold text-sm shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
        Secure My Tickets <i data-lucide="lock" class="w-4 h-4"></i>
    </button>
</div>

<script>
    lucide.createIcons();
    let cart = [];

    // --- RENDER CART LOGIC ---
    function renderCart() {
        // 1. Get Cart
        cart = JSON.parse(localStorage.getItem('cart')) || [];

        const container = document.getElementById('cart-items-container');
        const emptyState = document.getElementById('empty-state');
        const footer = document.getElementById('checkout-footer');
        const upsell = document.getElementById('upsell-container');
        const countBadge = document.getElementById('cart-count');
        const totalEl = document.getElementById('total-amount');

        container.innerHTML = ''; // Clear current list

        if (cart.length === 0) {
            emptyState.classList.remove('hidden');
            footer.classList.add('hidden');
            upsell.classList.add('hidden');
            countBadge.innerText = '0 Items';
            // Also update backend that cart is empty
            syncCartToBackend([], 0);
            return;
        }

        // 2. Show UI
        emptyState.classList.add('hidden');
        footer.classList.remove('hidden');
        upsell.classList.remove('hidden');
        countBadge.innerText = `${cart.length} Items`;

        let grandTotal = 0;

        // 3. Build HTML
        cart.forEach((item, index) => {
            grandTotal += parseFloat(item.price);

            const div = document.createElement('div');
            div.className = "bg-white rounded-2xl p-4 shadow-sm border border-gray-100 relative overflow-hidden group";

            // Build numbers HTML
            const numbersHtml = item.numbers.map(n =>
                `<span class="bg-gray-100 text-gray-600 text-[10px] font-bold px-1.5 py-0.5 rounded border border-gray-200">${n}</span>`
            ).join('');

            div.innerHTML = `
                <div class="absolute top-0 left-0 bg-green-500 w-1 h-full"></div>

                <div class="flex justify-between items-start mb-3 pl-2">
                    <div>
                        <span class="bg-blue-50 text-blue-600 text-[10px] font-bold px-2 py-0.5 rounded mb-1 inline-block">Raffle Entry</span>
                        <h3 class="font-bold text-gray-900">${item.raffle_name || 'Raffle Ticket'}</h3>
                    </div>
                    <button onclick="removeItem(${index})" class="text-gray-300 hover:text-red-500 transition-colors p-1">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>

                <div class="pl-2 flex justify-between items-end">
                    <div>
                        <p class="text-[10px] text-gray-400 mb-1">Your Numbers:</p>
                        <div class="flex gap-1 flex-wrap max-w-[200px]">
                            ${numbersHtml}
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="block font-bold text-lg text-gray-900">₦${parseFloat(item.price).toLocaleString()}</span>
                    </div>
                </div>
            `;
            container.appendChild(div);
        });

        // 4. Update Total
        totalEl.innerText = '₦' + grandTotal.toLocaleString();

        // 5. Sync to Backend (Abandonment Tracking)
        syncCartToBackend(cart, grandTotal);

        lucide.createIcons();
    }

    function removeItem(index) {
        if(confirm("Remove this ticket?")) {
            cart.splice(index, 1);
            localStorage.setItem('cart', JSON.stringify(cart));
            renderCart();
        }
    }

    // --- ABANDONMENT TRACKER SYNC ---
    async function syncCartToBackend(cartItems, totalVal) {
        const token = localStorage.getItem('token');
        if (!token) return;

        try {
            await fetch((typeof API_CONFIG !== 'undefined' && API_CONFIG.CART_SYNC) ? API_CONFIG.CART_SYNC : 'ajax-router.php?action=cart_sync', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify({
                    cart: cartItems,
                    total: totalVal
                })
            });
        } catch (e) {
            console.error("Sync error", e);
        }
    }

    // --- TIMER ---
    function startTimer(duration, display) {
        let timer = duration, minutes, seconds;
        setInterval(function () {
            minutes = parseInt(timer / 60, 10);
            seconds = parseInt(timer % 60, 10);

            minutes = minutes < 10 ? "0" + minutes : minutes;
            seconds = seconds < 10 ? "0" + seconds : seconds;

            display.textContent = minutes + ":" + seconds;

            if (--timer < 0) {
                timer = 0;
                display.textContent = "EXPIRED";
                // Optional: Force redirect
            }
        }, 1000);
    }

    window.onload = function () {
        const display = document.querySelector('#cart-timer');
        startTimer(60 * 15, display);
        renderCart();
    };

    function goToCheckout() {
        if (cart.length === 0) return;

        // Aggregate Cart for Single Checkout
        const totalAmount = cart.reduce((sum, item) => sum + parseFloat(item.price), 0);
        const totalTickets = cart.reduce((sum, item) => sum + item.numbers.length, 0);

        // Flatten numbers for checkout logic (assuming single raffle checkout mostly)
        // If multi-raffle, your backend logic must handle parsing cart array.
        // For now, we send the first raffle ID and flatten numbers.
        const allNumbers = cart.flatMap(item => item.numbers).join(',');
        const firstRaffleId = cart[0].raffle_id || 0;

        window.location.href = `checkout.php?amount=${totalAmount}&tickets=${totalTickets}&numbers=${allNumbers}&raffle_id=${firstRaffleId}`;
    }
</script>

<?php include 'footer.php'; ?>
