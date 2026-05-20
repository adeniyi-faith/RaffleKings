    <div class="fixed top-0 left-0 w-full z-50 px-5 pt-4 safe-top pointer-events-none">
        <div class="flex justify-between items-center">
            <!-- Back Button -->
            <a href="index.php" class="pointer-events-auto bg-white/90 backdrop-blur-sm p-3 rounded-full shadow-sm text-gray-600 hover:text-gray-900 border border-gray-200 active:scale-95 transition-transform">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>

            <!-- Logo -->
            <div class="pointer-events-auto bg-white/90 backdrop-blur-sm px-4 py-2 rounded-full shadow-sm border border-gray-200">
                <h1 class="font-black text-sm tracking-tight text-gray-900 leading-none">Raffle<span class="text-app-primary">Kings</span></h1>
            </div>
        </div>
    </div>

    <!-- Main Content (Scrollable) -->
    <main class="flex-1 overflow-y-auto no-scrollbar relative w-full h-full snap-y snap-mandatory scroll-smooth" id="main-scroller">

        <!-- SECTION 1: INTRO HERO -->
        <section id="intro" class="w-full h-full flex flex-col justify-center items-center px-6 relative snap-start bg-white">
            <div class="absolute inset-0 bg-[radial-gradient(#e5e7eb_1px,transparent_1px)] [background-size:16px_16px] opacity-30 pointer-events-none"></div>

            <div class="text-center max-w-sm mx-auto relative z-10 fade-in-up">
                <div class="w-20 h-20 bg-blue-50 text-app-primary rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-sm transform -rotate-3">
                    <i data-lucide="crown" class="w-10 h-10 fill-current"></i>
                </div>
                <h2 class="text-3xl font-black text-gray-900 mb-4 tracking-tight">Welcome to the Kingdom</h2>
                <p class="text-gray-500 text-sm leading-relaxed mb-8">
                    RaffleKings is a community-driven prize platform. We are not a casino. We are a skill-based raffle system designed to reward loyal members.
                </p>
                <button @click="document.getElementById('how-it-works').scrollIntoView({behavior: 'smooth'})" class="animate-bounce">
                    <i data-lucide="chevron-down" class="w-6 h-6 text-gray-400"></i>
                </button>
            </div>
        </section>

        <!-- SECTION 2: HOW IT WORKS (3 STEPS) -->
        <section id="how-it-works" class="w-full h-full flex flex-col justify-center px-6 relative snap-start bg-gray-50">
            <div class="max-w-md mx-auto w-full">
                <h3 class="text-xs font-bold text-app-primary uppercase tracking-widest mb-1 fade-in-up">The Process</h3>
                <h2 class="text-2xl font-black text-gray-900 mb-8 fade-in-up delay-100">How to Win</h2>

                <div class="space-y-6">
                    <!-- Step 1 -->
                    <div class="flex gap-4 fade-in-up delay-200">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-white border border-gray-200 flex items-center justify-center font-bold text-gray-900 shadow-sm">1</div>
                        <div>
                            <h4 class="font-bold text-gray-900 mb-1">Fund Your Wallet</h4>
                            <p class="text-xs text-gray-500 leading-relaxed">Deposit cash via bank transfer or card. Your money is safe in your "Spending Wallet".</p>
                        </div>
                    </div>
                    <!-- Step 2 -->
                    <div class="flex gap-4 fade-in-up delay-300">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-white border border-gray-200 flex items-center justify-center font-bold text-gray-900 shadow-sm">2</div>
                        <div>
                            <h4 class="font-bold text-gray-900 mb-1">Buy Tickets</h4>
                            <p class="text-xs text-gray-500 leading-relaxed">Browse active raffles (Cash, Gadgets, Cars). Purchase tickets. The more you buy, the higher your odds.</p>
                        </div>
                    </div>
                    <!-- Step 3 -->
                    <div class="flex gap-4 fade-in-up" style="animation-delay: 0.4s;">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-green-100 border border-green-200 flex items-center justify-center font-bold text-green-700 shadow-sm">3</div>
                        <div>
                            <h4 class="font-bold text-gray-900 mb-1">Watch Live & Win</h4>
                            <p class="text-xs text-gray-500 leading-relaxed">Join the live draw every Friday. If your number is picked, your winnings are instantly credited.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- SECTION 3: WALLETS EXPLAINED -->
        <section id="wallets" class="w-full h-full flex flex-col justify-center px-6 relative snap-start bg-white">
            <div class="max-w-md mx-auto w-full text-center">
                <h2 class="text-2xl font-black text-gray-900 mb-6 fade-in-up">Your Two Wallets</h2>

                <div class="grid grid-cols-2 gap-4 mb-8">
                    <!-- Spending -->
                    <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100 fade-in-up delay-100">
                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3 text-blue-600">
                            <i data-lucide="wallet" class="w-5 h-5"></i>
                        </div>
                        <h4 class="font-bold text-gray-900 text-sm mb-1">Spending</h4>
                        <p class="text-[10px] text-gray-500 leading-tight">Used ONLY to buy tickets. Cannot be withdrawn.</p>
                    </div>

                    <!-- Earnings -->
                    <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100 fade-in-up delay-200">
                        <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3 text-green-600">
                            <i data-lucide="banknote" class="w-5 h-5"></i>
                        </div>
                        <h4 class="font-bold text-gray-900 text-sm mb-1">Earnings</h4>
                        <p class="text-[10px] text-gray-500 leading-tight">Winnings go here. Can be withdrawn to bank instantly.</p>
                    </div>
                </div>

                <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-4 text-left flex gap-3 fade-in-up delay-300">
                    <i data-lucide="info" class="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5"></i>
                    <p class="text-xs text-yellow-800">
                        <strong>Pro Tip:</strong> You can transfer from Earnings to Spending to buy more tickets, but you cannot transfer back.
                    </p>
                </div>
            </div>
        </section>

        <!-- SECTION 4: FAIRNESS -->
        <section id="fairness" class="w-full h-full flex flex-col justify-center px-6 relative snap-start bg-gray-900 text-white">
            <div class="max-w-md mx-auto w-full">
                <div class="w-16 h-16 bg-white/10 rounded-2xl flex items-center justify-center mb-6 backdrop-blur-md fade-in-up">
                    <i data-lucide="shield-check" class="w-8 h-8 text-green-400"></i>
                </div>

                <h2 class="text-2xl font-black mb-4 fade-in-up delay-100">100% Transparent</h2>
                <p class="text-sm text-gray-300 mb-8 leading-relaxed fade-in-up delay-200">
                    We don't pick winners manually. Our system uses a cryptographic Random Number Generator (RNG) during the live stream.
                </p>

                <ul class="space-y-4 fade-in-up delay-300">
                    <li class="flex items-center gap-3">
                        <i data-lucide="check-circle" class="w-5 h-5 text-app-primary"></i>
                        <span class="text-sm font-medium">Live Streamed Draws</span>
                    </li>
                    <li class="flex items-center gap-3">
                        <i data-lucide="check-circle" class="w-5 h-5 text-app-primary"></i>
                        <span class="text-sm font-medium">Public Winner Ledger</span>
                    </li>
                    <li class="flex items-center gap-3">
                        <i data-lucide="check-circle" class="w-5 h-5 text-app-primary"></i>
                        <span class="text-sm font-medium">Instant Payouts</span>
                    </li>
                </ul>

                <a href="raffles.php" class="mt-10 block w-full bg-white text-gray-900 text-center py-4 rounded-xl font-bold hover:bg-gray-100 transition-colors fade-in-up" style="animation-delay: 0.4s;">
                    Get Started Now
                </a>
            </div>
        </section>

    </main>

    <!-- Bottom Dots Navigation -->
    <div class="fixed right-4 top-1/2 -translate-y-1/2 flex flex-col gap-2 z-40">
        <template x-for="section in ['intro', 'how-it-works', 'wallets', 'fairness']">
            <a :href="'#' + section"
               class="w-2 h-2 rounded-full transition-all duration-300"
               :class="activeSection === section ? 'bg-app-primary h-4' : 'bg-gray-300 hover:bg-gray-400'"
               @click="activeSection = section">
            </a>
        </template>
    </div>
