<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Daily Spin - RaffleKings</title>
    
    <!-- Core Assets -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    
    <!-- Config -->
    <script src="config.js"></script>

    <script>
        // 1. Dark Mode Logic (Immediate Execution to prevent flash)
        (function() {
            const localTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
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
                        'app-primary': '#2563eb',
                        'dark-bg': '#0f172a',
                        'dark-card': '#1e293b'
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        /* Immersive Reset */
        body { font-family: 'Inter', sans-serif; -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* --- NEW ANIMATIONS --- */
        
        /* 1. Portal Glow (Replaces Rectangle) */
        .wheel-glow-bg {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 320px; height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(250,204,21,0.4) 0%, rgba(250,204,21,0) 70%);
            z-index: 0;
            animation: pulse-glow 3s ease-in-out infinite;
        }
        .dark .wheel-glow-bg {
            background: radial-gradient(circle, rgba(168,85,247,0.4) 0%, rgba(168,85,247,0) 70%);
        }

        @keyframes pulse-glow {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.5; }
            50% { transform: translate(-50%, -50%) scale(1.1); opacity: 0.8; }
        }

        /* 2. Wheel Spin Animation (Rotation) */
        @keyframes spin-slow {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* 3. Button Gradient & Active State */
        .spin-btn-primary {
            background: linear-gradient(135deg, #facc15 0%, #eab308 100%);
            box-shadow: 0 4px 15px rgba(234, 179, 8, 0.4);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .spin-btn-primary:active:not(:disabled) { 
            transform: scale(0.95); 
            box-shadow: 0 2px 5px rgba(234, 179, 8, 0.3); 
        }
        .spin-btn-primary:disabled { 
            opacity: 0.7; 
            cursor: not-allowed; 
            filter: grayscale(0.8);
            box-shadow: none;
        }
        
        /* Marquee */
        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-100%); }
        }
        .animate-marquee { animation: marquee 25s linear infinite; }
    </style>
</head>

<body class="bg-gray-50 dark:bg-dark-bg text-gray-900 dark:text-white h-full flex flex-col transition-colors duration-300">

    <!-- 1. IMMERSIVE HEADER -->
    <header class="px-5 pt-6 pb-2 flex-none z-10">
        <div class="flex justify-between items-center">
            <a href="index.php" class="p-2.5 bg-white dark:bg-dark-card rounded-full border border-gray-200 dark:border-gray-700 shadow-sm active:scale-90 transition-transform">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-200"></i>
            </a>
            
            <!-- Live Points Display -->
            <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-gray-700 px-4 py-2 rounded-full flex items-center gap-2 shadow-sm font-bold text-sm">
                <div class="w-5 h-5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                    <i data-lucide="coins" class="w-3 h-3 text-yellow-600 dark:text-yellow-400 fill-current"></i>
                </div>
                <span id="header-points" class="text-gray-900 dark:text-white font-mono">Loading...</span>
            </div>
        </div>
    </header>

    <!-- 2. MAIN GAME AREA -->
    <main class="flex-1 overflow-y-auto no-scrollbar flex flex-col items-center justify-center px-5 pb-20 w-full max-w-md mx-auto">
        
        <!-- Game Title -->
        <div class="text-center mb-8 relative z-10">
            <h1 class="text-4xl font-black uppercase italic tracking-tighter text-gray-900 dark:text-white drop-shadow-sm">
                Daily <span class="text-transparent bg-clip-text bg-gradient-to-r from-yellow-500 to-amber-600">Spin</span>
            </h1>
            <p class="text-xs font-bold tracking-widest uppercase text-gray-400 dark:text-gray-500 mt-1">Try your luck today</p>
        </div>

        <!-- The Wheel Container (No Rectangle, Floating) -->
        <div class="relative w-[320px] h-[320px] flex items-center justify-center mb-10">
            
            <!-- Beautiful Background Glow -->
            <div class="wheel-glow-bg"></div>

            <!-- Canvas Wrapper -->
            <div class="relative z-10 filter drop-shadow-2xl">
                <!-- Pointer -->
                <div class="absolute -top-6 left-1/2 -translate-x-1/2 z-30 filter drop-shadow-lg transform transition-transform duration-300 hover:scale-110">
                    <svg width="40" height="48" viewBox="0 0 24 24" fill="currentColor" class="text-red-600 dark:text-red-500">
                        <path d="M12 22L7 12H17L12 22ZM12 2C13.5 2 14.5 3 14.5 4.5V10H9.5V4.5C9.5 3 10.5 2 12 2Z" />
                    </svg>
                </div>

                <canvas id="wheel-canvas" width="600" height="600" style="width: 280px; height: 280px;"></canvas>
                
                <!-- Center Cap -->
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-16 h-16 bg-white dark:bg-dark-card rounded-full shadow-[0_0_15px_rgba(0,0,0,0.2)] flex items-center justify-center z-20 border-4 border-gray-100 dark:border-gray-800">
                    <i data-lucide="star" class="w-6 h-6 text-yellow-500 fill-current animate-pulse"></i>
                </div>
            </div>
        </div>

        <!-- Spin Button -->
        <div class="w-full relative z-20 px-4">
            <button id="spin-btn" onclick="startSpin()" class="spin-btn-primary w-full py-4 rounded-2xl text-xl font-black text-amber-900 flex items-center justify-center gap-3 group">
                <span>SPIN NOW</span>
                <span class="bg-white/30 px-2 py-0.5 rounded-lg text-xs font-bold text-amber-900 uppercase tracking-wide group-hover:bg-white/40 transition-colors">-50 Pts</span>
            </button>
            <p class="text-center text-[10px] text-gray-400 mt-3 font-medium">Guaranteed win on every 10th spin!</p>
        </div>

    </main>

    <!-- Footer Marquee -->
    <div class="fixed bottom-6 left-5 right-5 z-0">
        <div class="w-full bg-white/80 dark:bg-dark-card/80 backdrop-blur-md rounded-xl p-3 shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden relative">
             <div class="flex gap-8 animate-marquee whitespace-nowrap text-xs text-gray-600 dark:text-gray-300 font-medium">
                <span class="flex items-center gap-1.5"><i data-lucide="trophy" class="w-3 h-3 text-yellow-500"></i> Musa won ₦5,000</span>
                <span class="flex items-center gap-1.5"><i data-lucide="zap" class="w-3 h-3 text-blue-500"></i> Sarah won 50 Pts</span>
                <span class="flex items-center gap-1.5"><i data-lucide="star" class="w-3 h-3 text-purple-500"></i> Emeka won 150 Pts</span>
                <span class="flex items-center gap-1.5"><i data-lucide="gift" class="w-3 h-3 text-green-500"></i> Tunde won iPhone 15</span>
                <!-- Loop -->
                <span class="flex items-center gap-1.5"><i data-lucide="trophy" class="w-3 h-3 text-yellow-500"></i> Musa won ₦5,000</span>
                <span class="flex items-center gap-1.5"><i data-lucide="zap" class="w-3 h-3 text-blue-500"></i> Sarah won 50 Pts</span>
            </div>
        </div>
    </div>

    <!-- 3. RESULT MODAL -->
    <div id="result-modal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 backdrop-blur-sm p-4 hidden opacity-0 transition-opacity duration-300">
        <div class="bg-white dark:bg-dark-card w-full max-w-sm rounded-3xl p-8 text-center shadow-2xl transform scale-90 transition-transform duration-300 border border-gray-100 dark:border-gray-700" id="result-content">
            
            <div class="w-24 h-24 bg-yellow-50 dark:bg-yellow-900/20 rounded-full flex items-center justify-center mx-auto mb-6 ring-4 ring-yellow-100 dark:ring-yellow-900/40">
                <i data-lucide="gift" class="w-10 h-10 text-yellow-600 dark:text-yellow-400"></i>
            </div>
            
            <h2 class="text-3xl font-black text-gray-900 dark:text-white mb-2 italic tracking-tight" id="modal-title">YOU WON!</h2>
            <p class="text-sm font-bold text-gray-500 dark:text-gray-400 mb-8" id="modal-message">...</p>
            
            <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 mb-6 border border-gray-100 dark:border-gray-700">
                <span class="text-xs text-gray-400 font-bold uppercase tracking-wider">New Balance</span>
                <span class="text-lg font-mono font-bold text-app-primary" id="modal-balance">...</span>
            </div>

            <button onclick="closeModal()" class="w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-bold py-4 rounded-xl shadow-lg active:scale-95 transition-transform">
                Awesome, Continue
            </button>
        </div>
    </div>

    <!-- 4. LOGIC -->
    <script>
        // --- CONSTANTS ---
        // Colors updated for better visibility in both modes
        const PRIZES = [
            { label: "15 Pts",  color: "#ef4444", text: "#ffffff" }, // Red
            { label: "50 Pts",  color: "#3b82f6", text: "#ffffff" }, // Blue
            { label: "150 Pts", color: "#eab308", text: "#ffffff" }, // Yellow
            { label: "10x WIN", color: "#22c55e", text: "#ffffff" }  // Green
        ];

        // --- STATE ---
        let currentPoints = 0;
        let isSpinning = false;
        let currentRotation = 0;
        let ctx = null;
        let canvas = null;

        // --- INIT ---
        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
            initWheel();
            
            // Critical Check: Config & Token
            if (typeof API_CONFIG === 'undefined') {
                console.error("CRITICAL: config.js not loaded.");
                document.getElementById('header-points').innerText = "Err: Config";
                return;
            }

            const token = localStorage.getItem('token');
            if(!token) {
                // Redirect if not logged in
                console.warn("No token found, redirecting...");
                // window.location.href = 'login.php'; // Uncomment for production
                document.getElementById('header-points').innerText = "Login Req";
            } else {
                fetchBalance();
            }
        });

        // --- CANVAS RENDERING ---
        function initWheel() {
            canvas = document.getElementById('wheel-canvas');
            if(!canvas) return;
            
            const dpr = window.devicePixelRatio || 1;
            const cssSize = 280; // Matches visual size
            
            canvas.width = cssSize * dpr;
            canvas.height = cssSize * dpr;
            
            ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);
            
            draw(0);
        }

        function draw(rotationAngle) {
            if(!ctx) return;
            const size = 280; 
            const cx = size / 2;
            const cy = size / 2;
            const radius = size / 2 - 2; 
            const arc = (2 * Math.PI) / PRIZES.length;

            ctx.clearRect(0, 0, size, size);
            
            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(rotationAngle);
            ctx.translate(-cx, -cy);

            PRIZES.forEach((prize, i) => {
                const angle = i * arc;
                
                // Slice
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, radius, angle, angle + arc);
                ctx.fillStyle = prize.color;
                ctx.fill();
                // Add white border between slices
                ctx.strokeStyle = "#ffffff"; 
                ctx.lineWidth = 4;
                ctx.stroke();

                // Text
                ctx.save();
                ctx.translate(cx, cy);
                ctx.rotate(angle + arc / 2);
                ctx.textAlign = "right";
                ctx.fillStyle = prize.text;
                ctx.font = "bold 20px Inter, sans-serif"; // Larger font
                ctx.shadowColor = "rgba(0,0,0,0.2)";
                ctx.shadowBlur = 2;
                ctx.fillText(prize.label, radius - 20, 5);
                ctx.restore();
            });
            ctx.restore();
        }

        // --- GAME LOGIC ---
        function easeOutQuart(x) { return 1 - Math.pow(1 - x, 4); }

        async function startSpin() {
            if(isSpinning) return;
            
            const token = localStorage.getItem('token');
            if(!token) { alert("Please login to spin."); return; }

            // Logic: Ensure they have enough points
            if(currentPoints < 50) {
                alert("Insufficient Points! You need 50 points to spin.");
                return;
            }

            // UI Update
            isSpinning = true;
            const btn = document.getElementById('spin-btn');
            btn.disabled = true;
            btn.innerHTML = `<span class="animate-pulse">SPINNING...</span>`;
            
            // 1. Optimistic Deduction (Visually remove 50 points immediately)
            const optimisticPoints = currentPoints - 50;
            updatePointsDisplay(optimisticPoints);

            try {
                // API CALL
                console.log("Initiating Spin API call...");
                const res = await fetch(API_CONFIG.SPIN_WHEEL, {
                    method: 'POST',
                    headers: { 
                        'Authorization': `Bearer ${token}`,
                        'Content-Type': 'application/json' 
                    }
                });
                
                const data = await res.json();
                console.log("Spin API Response:", data);

                if(!data.success) {
                    throw new Error(data.message || "Spin failed");
                }

                // Animation Params
                const winIndex = data.visual_index; 
                const segmentAngle = (2 * Math.PI) / PRIZES.length;
                const offset = -Math.PI / 2; // Top
                const targetAngle = offset - (winIndex * segmentAngle) - (segmentAngle / 2);
                const randomOffset = (Math.random() - 0.5) * (segmentAngle * 0.8);
                const spins = 5 * 2 * Math.PI; 
                
                const currentMod = currentRotation % (2 * Math.PI);
                const totalRotation = spins + (targetAngle - currentMod) + randomOffset;
                
                // Animation Loop
                const startTime = performance.now();
                const duration = 4000;
                const startRot = currentRotation;

                function animate(time) {
                    const elapsed = time - startTime;
                    if(elapsed < duration) {
                        const t = elapsed / duration;
                        const ease = easeOutQuart(t);
                        currentRotation = startRot + (totalRotation * ease);
                        draw(currentRotation);
                        requestAnimationFrame(animate);
                    } else {
                        currentRotation = startRot + totalRotation;
                        draw(currentRotation);
                        finishSpin(data, winIndex);
                    }
                }
                requestAnimationFrame(animate);

            } catch(e) {
                console.error("Spin Error:", e);
                alert(e.message || "Network connection failed.");
                isSpinning = false;
                btn.disabled = false;
                btn.innerHTML = `<span>SPIN NOW</span><span class="bg-white/30 px-2 py-0.5 rounded-lg text-xs font-bold text-amber-900">-50 Pts</span>`;
                // Revert points if error
                fetchBalance(); 
            }
        }

        function finishSpin(data, winIndex) {
            isSpinning = false;
            const btn = document.getElementById('spin-btn');
            btn.disabled = false;
            btn.innerHTML = `<span>SPIN AGAIN</span><span class="bg-white/30 px-2 py-0.5 rounded-lg text-xs font-bold text-amber-900">-50 Pts</span>`;
            
            // Set final balance from server (which should handle the -50 + winnings)
            currentPoints = data.new_balance;
            updatePointsDisplay(currentPoints);
            
            showModal(PRIZES[winIndex].label, data.new_balance);
            confetti({ particleCount: 150, spread: 70, origin: { y: 0.6 } });
        }

        // --- HELPERS ---
        async function fetchBalance() {
            const token = localStorage.getItem('token');
            if(!token) return;
            
            try {
                // Ensure we use the correct URL from config.js
                console.log("Fetching balance from:", API_CONFIG.REWARDS_STATE);
                const res = await fetch(API_CONFIG.REWARDS_STATE, {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const data = await res.json();
                console.log("Balance Data:", data);
                
                // Parse points safely
                currentPoints = parseInt(data.points);
                if(isNaN(currentPoints)) currentPoints = 0;
                
                updatePointsDisplay(currentPoints);
            } catch(e) { 
                console.error("Balance Sync Error", e); 
                document.getElementById('header-points').innerText = "Err";
            }
        }

        function updatePointsDisplay(pts) {
            const el = document.getElementById('header-points');
            el.innerText = pts.toLocaleString() + " Pts";
        }

        // --- MODAL ---
        function showModal(label, balance) {
            const modal = document.getElementById('result-modal');
            const content = document.getElementById('result-content');
            
            document.getElementById('modal-message').innerText = `You landed on ${label}`;
            document.getElementById('modal-balance').innerText = balance.toLocaleString() + " Pts";
            
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                content.classList.remove('scale-90');
                content.classList.add('scale-100');
            }, 10);
        }

        window.closeModal = function() {
            const modal = document.getElementById('result-modal');
            const content = document.getElementById('result-content');
            modal.classList.add('opacity-0');
            content.classList.remove('scale-100');
            content.classList.add('scale-90');
            setTimeout(() => modal.classList.add('hidden'), 300);
        }
    </script>
</body>
</html>