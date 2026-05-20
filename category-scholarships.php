<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 relative">

    <!-- Header -->
    <div class="bg-white px-5 pt-2 pb-4 border-b border-gray-100 sticky top-0 z-40 shadow-sm">
        <h2 class="text-xl font-bold text-gray-900">Student Grants</h2>
        <p class="text-xs text-gray-500">Funds sent directly to your personal account.</p>
    </div>

    <!-- 1. Campus Rivalry Ticker (The Tribal Hook) -->
    <div class="bg-blue-900 text-white py-2 overflow-hidden relative">
        <div class="absolute left-0 top-0 bottom-0 w-8 bg-gradient-to-r from-blue-900 to-transparent z-10"></div>
        <div class="absolute right-0 top-0 bottom-0 w-8 bg-gradient-to-l from-blue-900 to-transparent z-10"></div>
        
        <div class="flex gap-6 animate-marquee whitespace-nowrap text-xs font-medium items-center">
            <span class="flex items-center gap-1">🔥 <strong class="text-yellow-400">UNILAG</strong> leading with 15 wins</span>
            <span class="flex items-center gap-1">📉 <strong class="text-red-300">LASU</strong> just dropped to 2nd</span>
            <span class="flex items-center gap-1">🚀 <strong class="text-green-400">OAU</strong> students active now</span>
            <span class="flex items-center gap-1">💰 <strong class="text-white">Fatima (ABU)</strong> won ₦50k Grant</span>
        </div>
    </div>

    <!-- 2. Hero: The "Pay Me" Promise -->
    <section class="p-5 pb-2">
        <div onclick="openScholarshipSheet('University Grant', 500, 'undergrad')" class="relative w-full h-64 rounded-3xl overflow-hidden shadow-xl shadow-orange-500/20 group cursor-pointer active:scale-[0.99] transition-transform bg-gradient-to-br from-orange-600 to-red-700">
            
            <!-- Abstract Background -->
            <div class="absolute top-0 right-0 w-64 h-64 bg-white/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
            <div class="absolute bottom-0 left-0 w-48 h-48 bg-black/10 rounded-full blur-3xl translate-y-1/3 -translate-x-1/3"></div>
            
            <div class="absolute right-4 top-4 bg-white/20 backdrop-blur-md px-2 py-1 rounded-lg text-[10px] font-bold text-white border border-white/20 animate-pulse">
                Next Draw: 6:00 PM
            </div>

            <!-- Content -->
            <div class="absolute bottom-0 left-0 w-full p-6 text-white z-10">
                <h3 class="text-3xl font-bold leading-none mb-1 shadow-black/5 drop-shadow-sm">₦150,000 Cash</h3>
                <p class="text-orange-100 text-sm mb-4 font-medium">For Tuition, Hostel, or upkeep. Paid to YOU.</p>
                
                <div class="flex items-center gap-3">
                    <button class="bg-white text-orange-700 px-5 py-2.5 rounded-xl text-xs font-bold shadow-lg active:scale-95 transition-transform flex-1">
                        Play for ₦500
                    </button>
                    <!-- The "Broke Student" Option -->
                    <button onclick="event.stopPropagation(); window.location.href='referrals.php'" class="bg-orange-800/50 backdrop-blur-md text-white border border-white/20 px-4 py-2.5 rounded-xl text-xs font-bold active:scale-95 transition-transform flex-1">
                        Earn Free Ticket
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- 3. Nightly "Data Money" (Retention Hook) -->
    <section class="px-5 py-2">
        <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-600">
                    <i data-lucide="wifi" class="w-5 h-5"></i>
                </div>
                <div>
                    <h4 class="text-sm font-bold text-indigo-900">Need Data?</h4>
                    <p class="text-[10px] text-indigo-600">50 students win ₦1,000 active data daily.</p>
                </div>
            </div>
            <button onclick="openScholarshipSheet('Data Support', 100, 'secondary')" class="text-xs font-bold bg-white border border-indigo-200 text-indigo-700 px-3 py-1.5 rounded-lg shadow-sm">
                Play @ ₦100
            </button>
        </div>
    </section>

    <!-- 4. Campus Leaderboard (Social Proof) -->
    <section class="px-5 py-6">
        <h3 class="text-sm font-bold text-gray-900 mb-3">Top Winning Schools (This Week)</h3>
        
        <div class="bg-white rounded-2xl border border-gray-100 p-4 shadow-sm space-y-4">
            <!-- Rank 1 -->
            <div class="flex items-center gap-3">
                <span class="text-lg font-bold text-yellow-500 w-4">1</span>
                <div class="flex-1">
                    <div class="flex justify-between mb-1">
                        <span class="text-xs font-bold text-gray-800">UNILAG</span>
                        <span class="text-xs font-bold text-green-600">₦450k Won</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="bg-yellow-400 h-1.5 rounded-full" style="width: 85%"></div>
                    </div>
                </div>
            </div>
            <!-- Rank 2 -->
            <div class="flex items-center gap-3">
                <span class="text-lg font-bold text-gray-400 w-4">2</span>
                <div class="flex-1">
                    <div class="flex justify-between mb-1">
                        <span class="text-xs font-bold text-gray-800">UNIBEN</span>
                        <span class="text-xs font-bold text-green-600">₦320k Won</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="bg-gray-400 h-1.5 rounded-full" style="width: 65%"></div>
                    </div>
                </div>
            </div>
            <!-- Rank 3 -->
            <div class="flex items-center gap-3">
                <span class="text-lg font-bold text-orange-400 w-4">3</span>
                <div class="flex-1">
                    <div class="flex justify-between mb-1">
                        <span class="text-xs font-bold text-gray-800">LASU</span>
                        <span class="text-xs font-bold text-green-600">₦150k Won</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="bg-orange-300 h-1.5 rounded-full" style="width: 40%"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 5. Student Winners (Relatable Social Proof) -->
    <section class="px-5 pb-6">
        <h3 class="text-sm font-bold text-gray-900 mb-3">Student Wall of Fame</h3>
        <div class="flex gap-3 overflow-x-auto no-scrollbar pb-2">
            
            <div class="min-w-[140px] bg-white p-3 rounded-xl border border-gray-100 shadow-sm text-center">
                <div class="w-10 h-10 rounded-full bg-gray-100 mx-auto mb-2 overflow-hidden">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Funmi" loading="lazy" />
                </div>
                <p class="text-xs font-bold text-gray-900">Funmi A.</p>
                <p class="text-[9px] text-gray-500 mb-1">UNILAG • 300L</p>
                <span class="text-[10px] bg-green-50 text-green-700 px-2 py-0.5 rounded font-bold">Won ₦100k</span>
            </div>

            <div class="min-w-[140px] bg-white p-3 rounded-xl border border-gray-100 shadow-sm text-center">
                <div class="w-10 h-10 rounded-full bg-gray-100 mx-auto mb-2 overflow-hidden">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=David" loading="lazy" />
                </div>
                <p class="text-xs font-bold text-gray-900">David O.</p>
                <p class="text-[9px] text-gray-500 mb-1">YABATECH • HND 1</p>
                <span class="text-[10px] bg-green-50 text-green-700 px-2 py-0.5 rounded font-bold">Won ₦50k</span>
            </div>

            <div class="min-w-[140px] bg-white p-3 rounded-xl border border-gray-100 shadow-sm text-center">
                <div class="w-10 h-10 rounded-full bg-gray-100 mx-auto mb-2 overflow-hidden">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Zainab" loading="lazy" />
                </div>
                <p class="text-xs font-bold text-gray-900">Zainab S.</p>
                <p class="text-[9px] text-gray-500 mb-1">ABU ZARIA • 200L</p>
                <span class="text-[10px] bg-green-50 text-green-700 px-2 py-0.5 rounded font-bold">Won ₦150k</span>
            </div>

        </div>
    </section>

</div>

<!-- Purchase Sheet -->
<div id="scholar-overlay" onclick="closeScholarshipSheet()" class="fixed inset-0 bg-black/60 z-50 hidden transition-opacity opacity-0 backdrop-blur-sm"></div>

<div id="scholar-sheet" class="fixed bottom-0 left-0 w-full bg-white rounded-t-3xl z-50 transform translate-y-full transition-transform duration-300 ease-out sm:max-w-md sm:left-1/2 sm:-translate-x-1/2 safe-bottom shadow-2xl h-auto">
    
    <div class="w-full flex justify-center pt-3 pb-1 flex-shrink-0" onclick="closeScholarshipSheet()">
        <div class="w-12 h-1.5 bg-gray-200 rounded-full"></div>
    </div>

    <div class="p-6 pt-2 pb-8">
        <div class="flex justify-between items-center mb-4">
            <h3 id="sheet-scholar-title" class="text-xl font-bold text-gray-900">Student Grant</h3>
            <p id="sheet-scholar-price" class="text-xl font-bold text-orange-600">₦500</p>
        </div>

        <!-- Student Details Form -->
        <div class="space-y-3 mb-6">
            <input type="text" id="input-school" placeholder="Your School (e.g UNILAG)" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-orange-200 outline-none">
            <div class="grid grid-cols-2 gap-3" id="undergrad-fields">
                <select class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3.5 text-sm outline-none text-gray-600">
                    <option>Level</option>
                    <option>100</option>
                    <option>200</option>
                    <option>300</option>
                    <option>400</option>
                    <option>500</option>
                </select>
                <input type="text" placeholder="Matric No (Optional)" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3.5 text-sm outline-none">
            </div>
        </div>

        <!-- Smart Bundle (Squad Buy) -->
        <div onclick="selectBundle(3)" id="bundle-3" class="border border-orange-200 bg-orange-50 p-3 rounded-xl cursor-pointer relative transition-all mb-4 flex items-center gap-3">
            <div class="w-8 h-8 bg-orange-100 rounded-full flex items-center justify-center text-orange-600">
                <i data-lucide="users" class="w-4 h-4"></i>
            </div>
            <div class="flex-1">
                <span class="text-xs font-bold text-gray-800">Squad Pack (3 Tickets)</span>
                <p class="text-[10px] text-gray-500">Buy for you & 2 friends. Higher chance!</p>
            </div>
            <span class="text-sm font-bold text-gray-900">₦1,200</span>
        </div>

        <button class="w-full bg-app-primary text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
            Pay from Wallet <i data-lucide="arrow-right" class="w-4 h-4"></i>
        </button>
        
        <div class="text-center mt-3">
            <a href="referrals.php" class="text-[10px] text-gray-400 underline decoration-dotted">Low Balance? Earn this ticket for free</a>
        </div>
    </div>
</div>

<style>
    /* Marquee Animation */
    @keyframes marquee {
        0% { transform: translateX(100%); }
        100% { transform: translateX(-100%); }
    }
    .animate-marquee {
        animation: marquee 20s linear infinite;
    }
</style>

<script>
    const scholarOverlay = document.getElementById('scholar-overlay');
    const scholarSheet = document.getElementById('scholar-sheet');
    const sheetTitle = document.getElementById('sheet-scholar-title');
    const sheetPriceDisplay = document.getElementById('sheet-scholar-price');
    const undergradFields = document.getElementById('undergrad-fields');
    const schoolInput = document.getElementById('input-school');

    function openScholarshipSheet(title, price, type) {
        sheetTitle.innerText = title;
        sheetPriceDisplay.innerText = '₦' + price.toLocaleString();

        // Dynamic Form Logic
        if (type === 'undergrad') {
            undergradFields.classList.remove('hidden');
            undergradFields.classList.add('grid');
            schoolInput.placeholder = "University / Polytechnic Name";
        } else {
            undergradFields.classList.add('hidden');
            undergradFields.classList.remove('grid');
            schoolInput.placeholder = "Secondary School Name";
        }

        scholarOverlay.classList.remove('hidden');
        setTimeout(() => {
            scholarOverlay.classList.remove('opacity-0');
            scholarSheet.classList.remove('translate-y-full');
            if(window.innerWidth >= 640) scholarSheet.classList.remove('sm:translate-y-[120%]');
        }, 10);
    }

    function closeScholarshipSheet() {
        scholarOverlay.classList.add('opacity-0');
        scholarSheet.classList.add('translate-y-full');
        setTimeout(() => {
            scholarOverlay.classList.add('hidden');
        }, 300);
    }
    
    function selectBundle(id) {
        // Simple visual selection logic
        const el = document.getElementById('bundle-' + id);
        el.classList.toggle('ring-2');
        el.classList.toggle('ring-orange-500');
    }
</script>

<?php include 'footer.php'; ?>