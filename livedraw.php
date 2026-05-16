<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#020617">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <title>Live Draw Reveal - RaffleKings</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        /* iOS CRITICAL FIXES */
        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        html {
            width: 100%;
            height: 100%;
            /* REMOVED: Fixed positioning on html causes issues */
            overflow: hidden;
            background-color: #020617;
        }

        body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            /* KEY FIX: Use dvh (dynamic viewport height) for iOS */
            min-height: 100vh;
            min-height: -webkit-fill-available;
            background-color: #020617;
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow: hidden;
            /* REMOVED: position fixed on body - causes Safari issues */
            position: relative;
        }

        /* iOS Safari dynamic height fix */
        @supports (-webkit-touch-callout: none) {
            body {
                min-height: -webkit-fill-available;
            }
        }

        [x-cloak] { display: none !important; }

        /* Container must handle height properly */
        .app-container {
            width: 100%;
            height: 100vh;
            height: 100dvh; /* Modern browsers */
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        /* Staggered Entry Animation */
        .reveal-item {
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal-item.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* High Energy Flash for Suspense */
        @keyframes flash-red-subtle {
            0% { transform: scale(0.95); opacity: 0.3; filter: blur(4px); }
            50% { transform: scale(1.05); opacity: 1; filter: blur(0px); }
            100% { transform: scale(1.1); opacity: 0; filter: blur(2px); }
        }
        .flash-animation { animation: flash-red-subtle 0.1s infinite; }

        .red-glow {
            box-shadow: 0 0 30px rgba(220, 38, 38, 0.2);
        }

        /* Scroll fixes for iOS */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* iOS scroll momentum */
        .scroll-container {
            -webkit-overflow-scrolling: touch;
            overflow-y: auto;
            /* CRITICAL: Give it explicit height */
            flex: 1;
            min-height: 0;
        }

        /* Prevent iOS rubber-band effect */
        .scroll-container {
            overscroll-behavior-y: contain;
        }

        /* Safe area padding - iOS notch support */
        .safe-pb {
            padding-bottom: calc(1.5rem + env(safe-area-inset-bottom));
        }
        .safe-pt {
            padding-top: calc(1rem + env(safe-area-inset-top));
        }

        .progress-bar-fill {
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Loading animation */
        @keyframes loading {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Fix for flex containers on iOS */
        .flex-1-ios {
            flex: 1;
            min-height: 0; /* Critical for flex scrolling */
            display: flex;
            flex-direction: column;
        }

        /* Background layers must not interfere */
        .bg-layer {
            position: absolute;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }
    </style>

    <script>
        function liveDraw() {
            return {
                view: 'intro',
                loading: false,
                loadProgress: 0,
                loadingText: 'Initializing Engine...',
                raffleTitle: '',
                winners: [],
                visibleWinners: [],
                participants: [],
                flashingParticipant: null,
                allRevealed: false,
                totalEntries: 0,

                async init() {
                    // iOS height fix
                    const setAppHeight = () => {
                        const vh = window.innerHeight * 0.01;
                        document.documentElement.style.setProperty('--vh', `${vh}px`);
                    };

                    setAppHeight();
                    window.addEventListener('resize', setAppHeight);
                    window.addEventListener('orientationchange', () => {
                        setTimeout(setAppHeight, 100);
                    });

                    this.fetchResults();
                },

                async fetchResults() {
                    this.loading = true;
                    this.loadProgress = 10;

                    const start = Date.now();

                    try {
                        this.loadingText = 'Authenticating Request...';
                        this.loadProgress = 30;

                        const baseUrl = (typeof WORDPRESS_URL !== 'undefined') ? WORDPRESS_URL : '';
                        const url = API_CONFIG.DRAW_RESULTS;

                        const res = await fetch(url);
                        this.loadProgress = 60;
                        this.loadingText = 'Decrypting Raffle Vault...';

                        const data = await res.json();
                        this.loadProgress = 85;
                        this.loadingText = 'Formatting Standings...';

                        if (data && (data.status === 'active' || data.status === 'completed')) {
                            this.raffleTitle = data.raffle_title || 'Latest Results';
                            this.winners = data.winners || [];
                            this.participants = data.participants || [];
                            this.totalEntries = data.total_pool_size || this.participants.length;
                            this.winners.sort((a, b) => b.rank - a.rank);
                        }

                        this.loadProgress = 100;
                        await new Promise(r => setTimeout(r, 400));

                    } catch (e) {
                        console.error("Fetch Error:", e);
                    } finally {
                        this.loading = false;
                        this.$nextTick(() => {
                            if(window.lucide) window.lucide.createIcons();
                        });
                    }
                },

                startRevealSequence() {
                    if (this.winners.length === 0) return;
                    this.view = 'flashing';
                    let flashCount = 0;
                    const maxFlash = 30;

                    const flashInterval = setInterval(() => {
                        if (this.participants.length > 0) {
                            this.flashingParticipant = this.participants[Math.floor(Math.random() * this.participants.length)];
                        }
                        flashCount++;
                        if (flashCount >= maxFlash) {
                            clearInterval(flashInterval);
                            this.showResults();
                        }
                    }, 100);
                },

                showResults() {
                    this.view = 'results';
                    this.visibleWinners = [];
                    this.allRevealed = false;
                    let i = 0;
                    const revealInterval = setInterval(() => {
                        if (i < this.winners.length) {
                            this.visibleWinners.unshift(this.winners[i]);
                            i++;
                            setTimeout(() => { if(window.lucide) window.lucide.createIcons(); }, 50);
                        } else {
                            clearInterval(revealInterval);
                            this.allRevealed = true;
                        }
                    }, 1200);
                }
            }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="text-white bg-slate-950">

    <div x-data="liveDraw()" x-init="init()" class="app-container" x-cloak>

        <!-- Background Layer -->
        <div class="bg-layer">
            <div class="absolute top-0 left-0 w-full h-full bg-gradient-to-b from-red-900/10 to-transparent"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[70%] h-[70%] bg-red-600/5 blur-[120px] rounded-full"></div>
        </div>

        <!-- MAIN CONTENT -->
        <main class="relative z-10 flex-1-ios">

            <!-- PHASE 1: INTRO -->
            <template x-if="view === 'intro'">
                <div class="flex-1 flex flex-col items-center justify-center px-6 text-center">

                    <template x-if="loading">
                        <div class="w-full max-w-xs py-10">
                            <h2 class="text-xl font-black mb-6 tracking-widest uppercase text-white/90" x-text="loadingText"></h2>
                            <div class="w-full h-1.5 bg-white/10 rounded-full overflow-hidden shadow-inner">
                                <div class="h-full bg-red-600 progress-bar-fill shadow-[0_0_15px_rgba(220,38,38,0.5)]" :style="'width: ' + loadProgress + '%'"></div>
                            </div>
                            <p class="mt-4 text-[9px] font-bold text-white/30 tracking-[0.3em] uppercase" x-text="loadProgress + '%'"></p>
                        </div>
                    </template>

                    <template x-if="!loading && winners.length === 0">
                        <div class="p-8 bg-white/5 border border-white/10 rounded-[2.5rem] backdrop-blur-xl">
                            <h2 class="text-xl font-black mb-2 uppercase tracking-tight">Vault Empty</h2>
                            <p class="text-white/40 text-xs leading-relaxed">No draw results have been published for this session yet.</p>
                            <button @click="fetchResults()" class="mt-6 text-[10px] font-black text-red-500 uppercase tracking-widest hover:underline">Retry Connection</button>
                        </div>
                    </template>

                    <template x-if="!loading && winners.length > 0">
                        <div class="w-full max-w-sm">
                            <div class="mb-4 inline-block px-3 py-1 rounded-full bg-red-600/10 border border-red-500/20">
                                <span class="text-[9px] font-black uppercase tracking-[0.3em] text-red-500">Live Engine Ready</span>
                            </div>
                            <h2 class="text-4xl md:text-5xl font-black mb-2 tracking-tighter" x-text="raffleTitle"></h2>
                            <p class="text-white/40 font-medium uppercase tracking-[0.4em] text-[10px] mb-12">Click below to start the reveal</p>

                            <button @click="startRevealSequence()"
                                    class="w-full py-5 rounded-[2rem] bg-red-600 hover:bg-red-700 text-white font-black text-lg shadow-2xl shadow-red-600/30 active:scale-95 transition-transform border-b-4 border-red-800 flex items-center justify-center gap-3">
                                <span>REVEAL WINNERS</span>
                                <i data-lucide="zap" class="w-5 h-5 fill-white"></i>
                            </button>
                        </div>
                    </template>
                </div>
            </template>

            <!-- PHASE 2: FLASHING -->
            <template x-if="view === 'flashing'">
                <div class="flex-1 flex flex-col items-center justify-center px-6">
                    <div class="mb-12 text-center">
                        <p class="text-red-500 font-black text-[10px] uppercase tracking-[0.6em] mb-4">Picking from Pool</p>
                        <div class="h-1 w-48 bg-white/10 rounded-full overflow-hidden mx-auto">
                            <div class="h-full bg-red-600 animate-[loading_1s_infinite]"></div>
                        </div>
                    </div>

                    <div class="h-64 flex items-center justify-center w-full">
                        <template x-if="flashingParticipant">
                            <div class="flash-animation flex flex-col items-center">
                                <div class="w-32 h-32 rounded-full border-4 border-red-600/30 p-1 mb-6 bg-slate-900 overflow-hidden shadow-2xl">
                                    <img :src="flashingParticipant.avatar" class="w-full h-full rounded-full object-cover grayscale">
                                </div>
                                <h3 class="text-4xl md:text-6xl font-black tracking-tighter uppercase italic text-center" x-text="flashingParticipant.name"></h3>
                                <p class="text-red-500 font-mono text-lg mt-2 font-bold" x-text="'TICKET #' + flashingParticipant.ticket"></p>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- PHASE 3: RESULTS -->
            <template x-if="view === 'results'">
                <div class="flex-1-ios">
                    <!-- Sticky Header -->
                    <div class="safe-pt p-6 pb-4 text-center border-b border-white/5 bg-slate-950/80 backdrop-blur-md sticky top-0 z-20">
                        <p class="text-red-500 text-[10px] font-black uppercase tracking-[0.5em] mb-1">Final Standings</p>
                        <h2 class="text-2xl font-black tracking-tight" x-text="raffleTitle"></h2>
                    </div>

                    <!-- Scrollable List -->
                    <div class="scroll-container no-scrollbar px-4 py-6">
                        <div class="max-w-md mx-auto space-y-4 pb-10">
                            <template x-for="winner in visibleWinners" :key="winner.db_id">
                                <div class="reveal-item visible bg-white/5 border border-white/10 rounded-[1.75rem] p-4 flex items-center gap-4 red-glow backdrop-blur-xl">
                                    <!-- Rank Badge -->
                                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-2xl font-black flex-shrink-0"
                                     :class="{
                                        'bg-red-600 text-white shadow-lg': winner.rank === 1,
                                        'bg-slate-300 text-slate-900': winner.rank === 2,
                                        'bg-orange-600 text-white': winner.rank === 3,
                                        'bg-white/10 text-white/40': winner.rank > 3
                                     }">
                                        <span x-text="winner.rank"></span>
                                    </div>

                                    <!-- Avatar -->
                                    <div class="w-12 h-12 rounded-full overflow-hidden border border-white/10 bg-slate-800 flex-shrink-0">
                                        <img :src="winner.img" class="w-full h-full object-cover">
                                    </div>

                                    <!-- Details -->
                                    <div class="flex-1 min-w-0">
                                        <h4 class="font-black text-sm truncate" x-text="winner.name"></h4>
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            <span class="inline-block px-2 py-0.5 bg-green-600/20 text-green-500 rounded-md text-[9px] font-black uppercase tracking-tighter" x-text="winner.prize"></span>
                                            <span class="inline-block px-2 py-0.5 bg-white/5 text-white/40 rounded-md text-[9px] font-mono" x-text="'#' + winner.id"></span>
                                        </div>
                                    </div>

                                    <!-- Check Mark -->
                                    <div class="flex-shrink-0">
                                        <template x-if="winner.is_credited">
                                            <div class="w-6 h-6 bg-green-500/10 rounded-full flex items-center justify-center">
                                                <i data-lucide="check" class="w-3.5 h-3.5 text-green-500"></i>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <div x-show="allRevealed" class="pt-8 pb-10 text-center">
                                <a href="index.php" class="inline-flex items-center gap-2 px-8 py-4 bg-white/5 hover:bg-white/10 border border-white/10 rounded-full font-black text-[10px] uppercase tracking-widest active:scale-95 transition-transform">
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                    <span>Back to Dashboard</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

        </main>

        <!-- Footer Stats -->
        <footer x-show="view === 'results'" class="safe-pb px-6 py-4 bg-slate-950 border-t border-white/5 flex justify-center gap-10 relative z-20 flex-shrink-0">
            <div class="text-center">
                <p class="text-white/20 text-[9px] font-black uppercase tracking-widest mb-0.5">Pool Entries</p>
                <p class="font-black text-xs" x-text="totalEntries"></p>
            </div>
            <div class="text-center">
                <p class="text-white/20 text-[9px] font-black uppercase tracking-widest mb-0.5">Status</p>
                <p class="font-black text-xs text-green-500 uppercase tracking-tighter italic">Verified</p>
            </div>
        </footer>
    </div>

    <script>
        window.addEventListener('load', () => {
            if(window.lucide) window.lucide.createIcons();
        });
    </script>
</body>
</html>