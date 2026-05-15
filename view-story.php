<?php include 'header.php'; ?>

<!-- 
    Story Viewer Specific Styles 
-->
<style>
    /* Hide standard App Header and Bottom Nav */
    header, nav, .safe-top, .safe-bottom {
        display: none !important;
    }
    
    /* Full screen black background */
    main {
        background-color: #000 !important;
        border: none !important;
        border-radius: 0 !important;
        max-width: 100% !important;
        height: 100vh !important;
        margin: 0 !important;
        overflow: hidden !important;
    }

    /* Glass effect */
    .glass-panel {
        background: rgba(0, 0, 0, 0.3);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
    
    .progress-bar { transition: width 0.1s linear; }

    /* Floating Heart Animation */
    @keyframes floatUp {
        0% { transform: translateY(0) scale(1) rotate(0deg); opacity: 1; }
        50% { opacity: 0.8; }
        100% { transform: translateY(-150px) scale(1.5) rotate(20deg); opacity: 0; }
    }
    
    .emoji-float {
        position: absolute;
        bottom: 80px; /* Start above the input bar */
        right: 40px; /* Align near the heart button */
        font-size: 24px;
        pointer-events: none;
        animation: floatUp 1.5s ease-out forwards;
        z-index: 50;
    }
</style>

<!-- Immersive Content Container -->
<div class="w-full h-full relative flex flex-col text-white">

    <!-- Container for Floating Emojis -->
    <div id="emoji-container" class="absolute inset-0 pointer-events-none z-50 overflow-hidden"></div>

    <!-- 1. Progress Bars (Top) -->
    <div class="absolute top-0 left-0 w-full z-30 pt-4 px-2 flex gap-1 safe-top-padding">
        <div class="h-1 bg-white/30 rounded-full flex-1 overflow-hidden">
            <div id="bar-0" class="h-full bg-white w-0"></div>
        </div>
        <div class="h-1 bg-white/30 rounded-full flex-1 overflow-hidden">
            <div id="bar-1" class="h-full bg-white w-0"></div>
        </div>
        <div class="h-1 bg-white/30 rounded-full flex-1 overflow-hidden">
            <div id="bar-2" class="h-full bg-white w-0"></div>
        </div>
    </div>

    <!-- 2. Header (User Info & Close) -->
    <div class="absolute top-6 left-0 w-full z-30 px-4 pt-4 flex justify-between items-center">
        <div class="flex items-center gap-2">
            <img id="story-avatar" src="https://placehold.co/100x100/2563EB/ffffff?text=CD" class="w-8 h-8 rounded-full border border-white/50">
            <div>
                <p id="story-user" class="text-sm font-bold text-white shadow-black drop-shadow-md">The Community Draw</p>
                <p id="story-time" class="text-[10px] text-gray-200 opacity-80">Loading...</p>
            </div>
        </div>
        <a href="index.php" class="p-2 bg-black/20 rounded-full backdrop-blur-sm active:scale-90 transition-transform">
            <i data-lucide="x" class="w-6 h-6 text-white"></i>
        </a>
    </div>

    <!-- 3. Main Content Area (Image/Video) -->
    <div id="story-content" class="absolute inset-0 bg-cover bg-center z-0 transition-all duration-300 ease-in-out">
        <div class="absolute inset-0 bg-gradient-to-b from-black/60 via-transparent to-black/90"></div>
    </div>

    <!-- 4. Tap Targets -->
    <div class="absolute inset-0 z-10 flex">
        <div class="w-1/3 h-full" onclick="prevStory()"></div> 
        <div class="w-2/3 h-full" onclick="nextStory()"></div> 
    </div>

    <!-- 5. Caption & Interaction (Bottom) -->
    <div class="absolute bottom-0 left-0 w-full z-30 p-5 pb-8 flex flex-col gap-4">
        
        <!-- Caption -->
        <div>
            <p id="story-caption" class="text-lg font-bold leading-tight drop-shadow-md mb-1"></p>
            <p id="story-location" class="text-xs text-gray-300 flex items-center gap-1">
                <i data-lucide="map-pin" class="w-3 h-3"></i> <span>Lagos, Nigeria</span>
            </p>
        </div>

        <!-- Product Context (Informational Only - No Button) -->
        <div id="story-cta" class="glass-panel p-3 rounded-2xl border border-white/10 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-black shadow-lg">
                <i id="cta-icon" data-lucide="smartphone" class="w-5 h-5"></i>
            </div>
            <div>
                <p class="text-[10px] text-gray-300 uppercase tracking-wide">Featured Prize</p>
                <p id="cta-text" class="text-sm font-bold text-white">iPhone 15 Pro</p>
            </div>
        </div>

        <!-- Engagement Bar -->
        <div class="flex gap-2 items-center mt-1">
            <input type="text" placeholder="Send a reaction..." class="bg-white/10 border border-white/20 text-white placeholder-gray-300 rounded-full px-4 py-3 w-full text-sm backdrop-blur-md outline-none focus:bg-white/20 transition-all relative z-40">
            
            <!-- Love Button with Animation Trigger -->
            <button onclick="triggerLove()" class="p-3 bg-white/10 rounded-full backdrop-blur-md active:scale-90 transition-transform border border-white/10 relative z-40 group">
                <i data-lucide="heart" class="w-5 h-5 text-white group-hover:text-red-500 group-hover:fill-red-500 transition-colors"></i>
            </button>
            
            <button class="p-3 bg-white/10 rounded-full backdrop-blur-md active:scale-90 transition-transform border border-white/10 relative z-40">
                <i data-lucide="send" class="w-5 h-5 text-white"></i>
            </button>
        </div>
    </div>

</div>

<script>
    // --- Mock Data with Placeholders ---
    const stories = [
        {
            id: 1,
            user: "Community Draw",
            avatar: "https://api.dicebear.com/7.x/initials/svg?seed=CD",
            time: "2h ago",
            location: "Ikeja, Lagos",
            media: "https://images.unsplash.com/photo-1598327105666-5b89351aff23?q=80&w=800&auto=format&fit=crop", // Placeholder: Phone
            caption: "Big Win! Tunde unboxing his new Spark 10. 🎉",
            cta: { icon: "smartphone", text: "Tecno Spark 10" }
        },
        {
            id: 2,
            user: "Community Draw",
            avatar: "https://api.dicebear.com/7.x/initials/svg?seed=CD",
            time: "1h ago",
            location: "Live Dashboard",
            media: "https://images.unsplash.com/photo-1550565118-c9fb33d834b0?q=80&w=800&auto=format&fit=crop", // Placeholder: Money
            caption: "Friday Pot is now ₦1.2 Million! 💰 Don't miss out.",
            cta: { icon: "banknote", text: "Friday Cash Pot" }
        },
        {
            id: 3,
            user: "Community Draw",
            avatar: "https://api.dicebear.com/7.x/initials/svg?seed=CD",
            time: "Just now",
            location: "Unilag, Yaba",
            media: "https://images.unsplash.com/photo-1523050854058-8df90110c9f1?q=80&w=800&auto=format&fit=crop", // Placeholder: Student
            caption: "Scholarship winner Sarah paying her fees today! 🎓",
            cta: { icon: "graduation-cap", text: "Tuition Grant" }
        }
    ];

    let currentIndex = 0;
    let progress = 0;
    let interval;
    const duration = 5000; 

    // Elements
    const elContent = document.getElementById('story-content');
    const elAvatar = document.getElementById('story-avatar');
    const elUser = document.getElementById('story-user');
    const elTime = document.getElementById('story-time');
    const elLocation = document.getElementById('story-location').querySelector('span');
    const elCaption = document.getElementById('story-caption');
    const elCtaText = document.getElementById('cta-text');
    const elCtaIcon = document.getElementById('cta-icon');

    function loadStory(index) {
        const story = stories[index];
        
        elContent.style.backgroundImage = `url('${story.media}')`;
        elAvatar.src = story.avatar;
        elUser.innerText = story.user;
        elTime.innerText = story.time;
        elLocation.innerText = story.location;
        elCaption.innerText = story.caption;
        
        // Update CTA (Info only)
        elCtaText.innerText = story.cta.text;
        elCtaIcon.setAttribute('data-lucide', story.cta.icon);
        
        if(window.lucide) window.lucide.createIcons();

        // Reset Bars
        stories.forEach((_, i) => {
            const bar = document.getElementById(`bar-${i}`);
            if(bar) {
                if (i < index) bar.style.width = '100%';
                else if (i > index) bar.style.width = '0%';
                else bar.style.width = '0%'; 
            }
        });

        clearInterval(interval);
        progress = 0;
        interval = setInterval(tick, 50);
    }

    function tick() {
        progress += (50 / duration) * 100;
        const currentBar = document.getElementById(`bar-${currentIndex}`);
        
        if (progress >= 100) {
            progress = 100;
            if(currentBar) currentBar.style.width = '100%';
            nextStory();
        } else {
            if(currentBar) currentBar.style.width = `${progress}%`;
        }
    }

    function nextStory() {
        if (currentIndex < stories.length - 1) {
            currentIndex++;
            loadStory(currentIndex);
        } else {
            window.location.href = 'index.php';
        }
    }

    function prevStory() {
        if (currentIndex > 0) {
            currentIndex--;
            loadStory(currentIndex);
        }
    }

    // --- Floating Love Animation ---
    function triggerLove() {
        const container = document.getElementById('emoji-container');
        const heart = document.createElement('div');
        
        // Array of hearts/love emojis
        const emojis = ['❤️', '💖', '🔥', '😍'];
        const randomEmoji = emojis[Math.floor(Math.random() * emojis.length)];
        
        heart.innerText = randomEmoji;
        heart.className = 'emoji-float';
        
        // Randomize horizontal position slightly
        const randomX = Math.floor(Math.random() * 60) - 30; // -30px to +30px
        heart.style.transform = `translateX(${randomX}px)`;
        
        container.appendChild(heart);

        // Remove element after animation
        setTimeout(() => {
            heart.remove();
        }, 1500);
    }

    window.onload = () => {
        loadStory(0);
        if(window.lucide) window.lucide.createIcons();
    };

</script>

<?php include 'footer.php'; ?>