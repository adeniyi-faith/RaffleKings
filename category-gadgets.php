<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 relative">

    <!-- Sticky Header & Filter -->
    <div class="bg-white px-5 pt-2 pb-2 sticky top-0 z-10 border-b border-gray-100 shadow-sm">
        <div class="flex justify-between items-center mb-3">
            <h2 class="text-xl font-bold text-gray-900">Gadgets</h2>
            <span class="text-[10px] font-medium bg-gray-100 text-gray-500 px-2 py-1 rounded flex items-center gap-1">
                <i data-lucide="check-circle" class="w-3 h-3"></i> Authentic (SLOT/Pointek)
            </span>
        </div>

        <!-- Brand Filters -->
        <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
            <button class="px-4 py-1.5 rounded-full bg-black text-white text-xs font-semibold whitespace-nowrap shadow-sm">
                All
            </button>
            <button class="px-4 py-1.5 rounded-full bg-white border border-gray-200 text-gray-600 text-xs font-medium whitespace-nowrap active:bg-gray-50">
                <i data-lucide="apple" class="w-3 h-3 inline mr-1 mb-0.5"></i> Apple
            </button>
            <button class="px-4 py-1.5 rounded-full bg-white border border-gray-200 text-gray-600 text-xs font-medium whitespace-nowrap active:bg-gray-50">
                Samsung
            </button>
            <button class="px-4 py-1.5 rounded-full bg-white border border-gray-200 text-gray-600 text-xs font-medium whitespace-nowrap active:bg-gray-50">
                Tecno / Infinix
            </button>
        </div>
    </div>

    <!-- Hero: The "Flagship" Highlight -->
    <section class="p-5 pb-2">
        <div onclick="openConfigSheet('iPhone 15 Pro Max', 2500, ['Natural Titanium', 'Blue Titanium', 'White Titanium', 'Black Titanium'])" class="relative w-full h-80 rounded-3xl overflow-hidden shadow-xl shadow-gray-200 group cursor-pointer active:scale-[0.99] transition-transform">
            <!-- Image -->
            <img src="https://images.unsplash.com/photo-1696446701796-da61225697cc?q=80&w=800&auto=format&fit=crop" loading="lazy"
                 class="w-full h-full object-cover object-center" alt="iPhone 15 Pro">
            
            <!-- Overlay Gradient -->
            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>

            <!-- Badges -->
            <div class="absolute top-4 left-4">
                <span class="bg-white/20 backdrop-blur-md border border-white/20 text-white text-[10px] font-bold px-2 py-1 rounded-lg uppercase tracking-wide">
                    Flagship Series
                </span>
            </div>
            
            <div class="absolute top-4 right-4 flex flex-col items-end">
                 <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center shadow-lg">
                    <span class="text-xs font-bold text-gray-900">₦2.5k</span>
                 </div>
                 <span class="text-[10px] text-white/80 mt-1 font-medium">per ticket</span>
            </div>

            <!-- Content -->
            <div class="absolute bottom-0 left-0 w-full p-6 text-white">
                <h3 class="text-3xl font-bold leading-none mb-1">iPhone 15 Pro</h3>
                <p class="text-gray-300 text-sm mb-4">Titanium Design. A17 Pro Chip.</p>
                
                <!-- Progress -->
                <div class="flex items-center gap-2 text-xs font-medium text-gray-300 mb-2">
                    <span>1,102 sold</span>
                    <span class="w-1 h-1 bg-gray-500 rounded-full"></span>
                    <span class="text-yellow-400">Ending soon</span>
                </div>
                <div class="w-full bg-white/20 rounded-full h-1.5 mb-4">
                    <div class="bg-yellow-400 h-1.5 rounded-full shadow-[0_0_10px_rgba(250,204,21,0.5)]" style="width: 78%"></div>
                </div>

                <button class="w-full bg-white text-black py-3 rounded-xl font-bold flex items-center justify-center gap-2">
                    Win This Phone <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    </section>

    <!-- Grid: Budget & Others -->
    <section class="px-5 pb-6">
        <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">Budget Friendly & Mid-Range</h3>
        
        <div class="grid grid-cols-2 gap-4">
            
            <!-- Item 1: Samsung A-Series -->
            <div onclick="openConfigSheet('Samsung Galaxy A54', 1000, ['Awesome Lime', 'Awesome Violet', 'Graphite', 'White'])" class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm active:scale-[0.98] transition-transform group cursor-pointer">
                <div class="relative w-full aspect-square bg-gray-50 rounded-xl mb-3 overflow-hidden">
                    <img src="https://images.unsplash.com/photo-1610945415295-d9bbf067e59c?q=80&w=400&auto=format&fit=crop" loading="lazy" class="w-full h-full object-cover mix-blend-multiply" alt="Samsung">
                    <div class="absolute bottom-2 left-2 flex gap-1">
                        <div class="w-2.5 h-2.5 rounded-full bg-green-400 border border-white shadow-sm"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-purple-500 border border-white shadow-sm"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-gray-800 border border-white shadow-sm"></div>
                    </div>
                </div>
                <h4 class="font-bold text-gray-900 text-sm truncate">Samsung Galaxy A54</h4>
                <div class="flex justify-between items-center mt-1">
                    <span class="text-xs text-gray-500">5G, 256GB</span>
                    <span class="text-sm font-bold text-app-primary">₦1,000</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-1 mt-3">
                    <div class="bg-blue-500 h-1 rounded-full" style="width: 45%"></div>
                </div>
                <p class="text-[10px] text-gray-400 mt-1">210 / 500 sold</p>
            </div>

            <!-- Item 2: Tecno Spark -->
            <div onclick="openConfigSheet('Tecno Spark 10 Pro', 500, ['Starry Black', 'Pearl White'])" class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm active:scale-[0.98] transition-transform group cursor-pointer">
                <div class="relative w-full aspect-square bg-gray-50 rounded-xl mb-3 overflow-hidden">
                    <!-- Updated Image URL -->
                    <img src="https://getonlinestudio.com/insights/wp-content/uploads/2025/12/spark-10.jpeg" loading="lazy" class="w-full h-full object-cover mix-blend-multiply" alt="Tecno">
                     <div class="absolute bottom-2 left-2 flex gap-1">
                        <div class="w-2.5 h-2.5 rounded-full bg-gray-900 border border-white shadow-sm"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-gray-100 border border-gray-300 shadow-sm"></div>
                    </div>
                </div>
                <h4 class="font-bold text-gray-900 text-sm truncate">Tecno Spark 10 Pro</h4>
                <div class="flex justify-between items-center mt-1">
                    <span class="text-xs text-gray-500">8GB RAM</span>
                    <span class="text-sm font-bold text-app-primary">₦500</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-1 mt-3">
                    <div class="bg-green-500 h-1 rounded-full" style="width: 15%"></div>
                </div>
                <p class="text-[10px] text-gray-400 mt-1">45 / 300 sold</p>
            </div>

            <!-- Item 3: AirPods -->
            <div onclick="openConfigSheet('AirPods Pro (2nd Gen)', 800, ['White'])" class="bg-white p-3 rounded-2xl border border-gray-100 shadow-sm active:scale-[0.98] transition-transform group cursor-pointer">
                <div class="relative w-full aspect-square bg-gray-50 rounded-xl mb-3 overflow-hidden">
                    <!-- Updated Image URL -->
                    <img src="https://getonlinestudio.com/insights/wp-content/uploads/2025/12/airpod-pro.jpeg" loading="lazy" class="w-full h-full object-cover mix-blend-multiply" alt="AirPods">
                </div>
                <h4 class="font-bold text-gray-900 text-sm truncate">AirPods Pro (2nd Gen)</h4>
                <div class="flex justify-between items-center mt-1">
                    <span class="text-xs text-gray-500">MagSafe</span>
                    <span class="text-sm font-bold text-app-primary">₦800</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-1 mt-3">
                    <div class="bg-blue-500 h-1 rounded-full" style="width: 60%"></div>
                </div>
                <p class="text-[10px] text-gray-400 mt-1">120 / 200 sold</p>
            </div>

            <!-- Item 4: Coming Soon -->
            <div class="bg-gray-50 p-3 rounded-2xl border border-dashed border-gray-300 flex flex-col items-center justify-center text-center">
                <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center mb-2 shadow-sm">
                    <i data-lucide="lock" class="w-5 h-5 text-gray-400"></i>
                </div>
                <h4 class="font-bold text-gray-500 text-sm">Mystery Drop</h4>
                <p class="text-[10px] text-gray-400">Unlocks Friday</p>
            </div>

        </div>
    </section>

</div>

<!-- Configure & Play Bottom Sheet -->
<div id="config-overlay" onclick="closeConfigSheet()" class="fixed inset-0 bg-black/60 z-50 hidden transition-opacity opacity-0 backdrop-blur-sm"></div>

<div id="config-sheet" class="fixed bottom-0 left-0 w-full bg-white rounded-t-3xl z-50 transform translate-y-full transition-transform duration-300 ease-out sm:max-w-md sm:left-1/2 sm:-translate-x-1/2 safe-bottom shadow-2xl">
    
    <!-- Handle -->
    <div class="w-full flex justify-center pt-3 pb-1" onclick="closeConfigSheet()">
        <div class="w-12 h-1.5 bg-gray-200 rounded-full"></div>
    </div>

    <div class="p-6 pt-2">
        <div class="flex items-start gap-4 mb-6">
            <div class="w-20 h-20 bg-gray-100 rounded-xl flex-shrink-0 overflow-hidden">
                <!-- Placeholder image for sheet -->
                <div class="w-full h-full flex items-center justify-center text-gray-300">
                    <i data-lucide="image" class="w-8 h-8"></i>
                </div>
            </div>
            <div>
                <p class="text-xs text-gray-400 font-medium uppercase tracking-wide">Configure Prize</p>
                <h3 id="sheet-product-title" class="text-xl font-bold text-gray-900 leading-tight">iPhone 15 Pro</h3>
                <p id="sheet-ticket-price" class="text-app-primary font-bold mt-1">₦2,500 <span class="text-gray-400 text-xs font-normal">/ ticket</span></p>
            </div>
        </div>

        <!-- Color Selection (The "Ownership" Hook) -->
        <div class="mb-6">
            <label class="text-sm font-bold text-gray-800 mb-3 block">Select Finish</label>
            <div id="color-options" class="flex flex-wrap gap-2">
                <!-- Injected via JS -->
            </div>
            <p class="text-[10px] text-gray-400 mt-2">If you win, this is the exact color we will ship to you.</p>
        </div>

        <!-- Quantity -->
        <div class="flex items-center justify-between bg-gray-50 rounded-xl p-2 mb-6 border border-gray-100">
            <button class="w-10 h-10 bg-white rounded-lg shadow-sm border border-gray-200 flex items-center justify-center text-gray-600">
                <i data-lucide="minus" class="w-4 h-4"></i>
            </button>
            <div class="text-center">
                <span class="text-xl font-bold text-gray-800 block">1</span>
                <span class="text-[10px] text-gray-400">Ticket</span>
            </div>
            <button class="w-10 h-10 bg-app-primary rounded-lg shadow-sm shadow-blue-200 flex items-center justify-center text-white">
                <i data-lucide="plus" class="w-4 h-4"></i>
            </button>
        </div>

        <button class="w-full bg-app-primary text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
            Confirm Selection & Play
        </button>
    </div>
</div>

<script>
    const configOverlay = document.getElementById('config-overlay');
    const configSheet = document.getElementById('config-sheet');
    const sheetProductTitle = document.getElementById('sheet-product-title');
    const sheetTicketPrice = document.getElementById('sheet-ticket-price');
    const colorOptionsContainer = document.getElementById('color-options');

    function openConfigSheet(title, price, colors) {
        sheetProductTitle.innerText = title;
        sheetTicketPrice.innerHTML = `₦${price.toLocaleString()} <span class="text-gray-400 text-xs font-normal">/ ticket</span>`;
        
        // Generate Color Chips
        colorOptionsContainer.innerHTML = '';
        colors.forEach((color, index) => {
            const btn = document.createElement('button');
            // Check if it's the first item to auto-select
            const isSelected = index === 0;
            const borderClass = isSelected ? 'border-app-primary ring-1 ring-app-primary bg-blue-50 text-app-primary' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300';
            
            btn.className = `px-4 py-2 rounded-lg border text-xs font-semibold transition-all ${borderClass}`;
            btn.innerText = color;
            
            // Simple click to select logic (visual only for now)
            btn.onclick = function() {
                // Reset all
                Array.from(colorOptionsContainer.children).forEach(c => {
                    c.className = 'px-4 py-2 rounded-lg border border-gray-200 bg-white text-gray-600 hover:border-gray-300 text-xs font-semibold transition-all';
                });
                // Select this
                this.className = 'px-4 py-2 rounded-lg border border-app-primary ring-1 ring-app-primary bg-blue-50 text-app-primary text-xs font-semibold transition-all';
            }

            colorOptionsContainer.appendChild(btn);
        });

        // Show Sheet
        configOverlay.classList.remove('hidden');
        setTimeout(() => {
            configOverlay.classList.remove('opacity-0');
            configSheet.classList.remove('translate-y-full');
            if(window.innerWidth >= 640) {
                 configSheet.classList.remove('sm:translate-y-[120%]');
            }
        }, 10);
    }

    function closeConfigSheet() {
        configOverlay.classList.add('opacity-0');
        configSheet.classList.add('translate-y-full');
        setTimeout(() => {
            configOverlay.classList.add('hidden');
        }, 300);
    }
</script>

<?php include 'footer.php'; ?>