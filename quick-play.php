<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 relative">

    <!-- Header -->
    <div class="bg-white px-5 pt-4 pb-4 border-b border-gray-100 sticky top-0 z-40 shadow-sm flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Live Raffles</h2>
            <p class="text-xs text-gray-500">Choose a category to play.</p>
        </div>
        <!-- Filter Icon (Visual Only) -->
        <button class="p-2 bg-gray-50 rounded-full text-gray-400">
            <i data-lucide="sliders-horizontal" class="w-5 h-5"></i>
        </button>
    </div>

    <!-- Category 1: Cash Jackpot (The Hero) -->
    <section class="p-5 pb-2">
        <div onclick="window.location.href='raffle-detail.php?type=cash'" class="group relative overflow-hidden rounded-3xl bg-gradient-to-br from-green-600 to-emerald-900 p-1 shadow-xl cursor-pointer active:scale-[0.98] transition-all">
            <!-- Background Image/Pattern -->
            <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10"></div>
            
            <div class="relative bg-gray-900/10 backdrop-blur-sm h-full rounded-[20px] p-5 flex flex-col justify-between min-h-[180px]">
                <div>
                    <span class="bg-yellow-400 text-green-900 text-[10px] font-bold px-2 py-1 rounded-full uppercase tracking-wide">
                        Ending Soon
                    </span>
                    <h3 class="text-2xl font-extrabold text-white mt-2 leading-tight">Weekly Cash<br>Jackpot</h3>
                    <p class="text-green-100 text-xs mt-1">Win up to ₦1,000,000 instantly.</p>
                </div>

                <div class="flex items-end justify-between mt-4">
                    <div>
                        <p class="text-[10px] text-green-200 uppercase">Ticket Price</p>
                        <p class="text-lg font-bold text-white">₦1,000</p>
                    </div>
                    <button class="bg-white text-green-700 px-4 py-2 rounded-full text-xs font-bold shadow-lg flex items-center gap-1 group-hover:gap-2 transition-all">
                        Play Now <i data-lucide="arrow-right" class="w-3 h-3"></i>
                    </button>
                </div>
            </div>

            <!-- Floating Coins (Visual) -->
            <div class="absolute top-4 right-4 w-16 h-16 bg-yellow-400 rounded-full blur-2xl opacity-20"></div>
        </div>
    </section>

    <!-- Category 2: Gadgets (Mobile Devices) -->
    <section class="px-5 pb-2">
        <div onclick="window.location.href='raffle-detail.php?type=gadget'" class="group relative overflow-hidden rounded-3xl bg-white border border-gray-100 p-1 shadow-sm cursor-pointer active:scale-[0.98] transition-all">
            <div class="flex h-full rounded-[20px] p-4 items-center gap-4">
                <!-- Image Side -->
                <div class="w-24 h-24 bg-blue-50 rounded-xl flex items-center justify-center flex-shrink-0">
                    <img src="https://img.icons8.com/3d-fluency/94/iphone-x.png" loading="lazy" class="w-16 h-16 object-contain drop-shadow-md group-hover:scale-110 transition-transform">
                </div>
                
                <!-- Text Side -->
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="bg-blue-100 text-blue-700 text-[9px] font-bold px-1.5 py-0.5 rounded">Gadgets</span>
                        <span class="text-[9px] text-gray-400">• 150 Tickets Left</span>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 leading-tight">iPhone 15 Pro Max</h3>
                    <p class="text-xs text-gray-500 mt-0.5 mb-3">Brand new, 256GB, any color.</p>
                    
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-app-primary">₦2,000 <span class="text-[10px] text-gray-400 font-normal">/ ticket</span></span>
                        <div class="w-8 h-8 rounded-full bg-gray-50 flex items-center justify-center border border-gray-100">
                            <i data-lucide="chevron-right" class="w-4 h-4 text-gray-400"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Category 3: Grants (Community) -->
    <section class="px-5 pb-5">
        <div onclick="window.location.href='raffle-detail.php?type=grant'" class="group relative overflow-hidden rounded-3xl bg-gradient-to-r from-purple-600 to-indigo-600 p-1 shadow-lg cursor-pointer active:scale-[0.98] transition-all">
            <div class="relative bg-white/10 backdrop-blur-md h-full rounded-[20px] p-5">
                <div class="flex justify-between items-start">
                    <div>
                        <div class="flex items-center gap-1.5 mb-2">
                            <i data-lucide="graduation-cap" class="w-4 h-4 text-purple-200"></i>
                            <span class="text-purple-100 text-[10px] font-bold uppercase tracking-wide">Education Grant</span>
                        </div>
                        <h3 class="text-xl font-bold text-white w-3/4 leading-tight">Student Support Fund</h3>
                    </div>
                    <div class="bg-white/20 p-2 rounded-lg backdrop-blur-sm">
                        <i data-lucide="heart-handshake" class="w-6 h-6 text-white"></i>
                    </div>
                </div>
                
                <p class="text-purple-100 text-xs mt-2 mb-4 line-clamp-2">
                    We are giving away 50 grants of ₦50,000 each to students. Low entry fee to support the community.
                </p>

                <div class="flex items-center justify-between border-t border-white/10 pt-3">
                    <span class="text-white font-bold">₦500 <span class="text-purple-200 text-xs font-normal">entry</span></span>
                    <span class="text-[10px] text-purple-200 bg-purple-900/30 px-2 py-1 rounded">Draws Sunday</span>
                </div>
            </div>
        </div>
    </section>

</div>

<script>
    lucide.createIcons();
</script>

<?php include 'footer.php'; ?>