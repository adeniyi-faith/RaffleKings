<?php include 'header.php'; ?>

<!-- Load Alpine.js -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>

<script>
    function tutorialApp() {
        return {
            isLoading: true,
            hasError: false,
            errorMessage: '',
            featured: null,
            articles: [],
            
            // Article Sheet State
            isSheetOpen: false,
            activeArticle: null,

            init() {
                this.fetchTutorials();
            },

            async fetchTutorials() {
                this.isLoading = true;
                this.hasError = false;
                this.errorMessage = '';
                
                try {
                    // Ensure API_CONFIG is loaded
                    if (typeof API_CONFIG === 'undefined') throw new Error("Configuration missing");

                    console.log("Fetching tutorials from:", API_CONFIG.TUTORIALS);

                    const res = await fetch(API_CONFIG.TUTORIALS);
                    
                    if (!res.ok) {
                        const text = await res.text();
                        console.error("API Error Response:", text);
                        throw new Error(`Server Error: ${res.status}`);
                    }

                    const data = await res.json();
                    
                    // Handle potential empty response or different structure
                    this.featured = data.featured || null;
                    this.articles = Array.isArray(data.list) ? data.list : [];
                    
                } catch(e) { 
                    console.error('Tutorial Fetch error', e); 
                    this.hasError = true;
                    this.errorMessage = e.message;
                } finally { 
                    this.isLoading = false; 
                }
            },

            openArticle(article) {
                if(!article) return;
                this.activeArticle = article;
                this.isSheetOpen = true;
            },

            closeArticle() {
                this.isSheetOpen = false;
                setTimeout(() => { this.activeArticle = null; }, 300);
            },

            async markHelpful(articleId, event) {
                if(event) event.stopPropagation();
                
                // Optimistic UI Update
                const target = this.articles.find(a => a.id === articleId) || (this.featured && this.featured.id === articleId ? this.featured : null);
                if(target) target.meta.helpful_count++;

                try {
                    await fetch(API_CONFIG.TUTORIAL_ACTION, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ id: articleId })
                    });
                } catch(e) {
                    console.error("Helpful action failed", e);
                    if(target) target.meta.helpful_count--; 
                }
            }
        }
    }
</script>

<style>
    [x-cloak] { display: none !important; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

<!-- Scrollable Content Area -->
<div x-data="tutorialApp()" class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 relative">

    <!-- Header -->
    <div class="bg-white px-5 pt-2 pb-4 border-b border-gray-100 sticky top-0 z-40 shadow-sm flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="index.php" class="p-1 -ml-1 text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <h2 class="text-xl font-bold text-gray-900">Learning Hub</h2>
        </div>
        <div class="flex gap-2">
            <!-- Refresh Button -->
            <button @click="fetchTutorials()" class="p-2 bg-gray-100 rounded-full text-gray-500 hover:bg-gray-200">
                <i data-lucide="rotate-cw" class="w-4 h-4"></i>
            </button>
        </div>
    </div>

    <!-- 1. LOADING STATE -->
    <div x-show="isLoading" class="p-10 text-center text-gray-400 text-sm flex flex-col items-center justify-center h-64">
        <i data-lucide="loader-2" class="w-8 h-8 animate-spin mb-3 text-app-primary"></i>
        <p>Loading guides...</p>
    </div>

    <!-- 2. ERROR STATE -->
    <div x-show="hasError && !isLoading" x-cloak class="p-10 text-center text-red-400 text-sm flex flex-col items-center justify-center h-64">
        <i data-lucide="wifi-off" class="w-8 h-8 mb-3"></i>
        <p>Could not load tutorials.</p>
        <p class="text-[10px] opacity-70 mt-1" x-text="errorMessage"></p>
        <button @click="fetchTutorials()" class="mt-4 px-4 py-2 bg-white border border-gray-200 rounded-lg text-xs font-bold text-gray-700 shadow-sm">Try Again</button>
    </div>

    <!-- 3. CONTENT STATE -->
    <div x-show="!isLoading && !hasError" x-cloak>
        
        <!-- A. Featured Training (Video) - Only shows if exists -->
        <template x-if="featured">
            <div class="p-5 pb-2">
                <div @click="openArticle(featured)" class="w-full aspect-video bg-gray-900 rounded-2xl flex items-center justify-center relative overflow-hidden shadow-lg group cursor-pointer mb-2">
                    <!-- Thumbnail Overlay -->
                    <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent z-10"></div>
                    <img :src="featured.thumbnail || 'https://images.unsplash.com/photo-1611162617474-5b21e879e113?q=80&w=1000&auto=format&fit=crop'" class="absolute inset-0 w-full h-full object-cover opacity-80 transition-transform duration-700 group-hover:scale-105">
                    
                    <div class="relative z-20 flex flex-col items-center">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur-md rounded-full flex items-center justify-center border border-white/30 group-hover:scale-110 transition-transform shadow-xl">
                            <i data-lucide="play" class="w-6 h-6 text-white fill-current ml-1"></i>
                        </div>
                    </div>
                    
                    <div class="absolute bottom-4 left-4 z-20">
                        <span class="bg-red-600 text-white text-[9px] font-bold px-2 py-0.5 rounded mb-1 inline-block">FEATURED</span>
                        <h3 class="text-white font-bold text-lg leading-tight" x-text="featured.title"></h3>
                        <p class="text-gray-300 text-xs">
                            By <span x-text="featured.author"></span> • <span x-text="featured.date_ago"></span>
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center justify-end px-1">
                    <div class="flex gap-3 text-gray-400">
                        <button @click.stop="markHelpful(featured.id, $event)" class="flex items-center gap-1 hover:text-red-500 transition-colors">
                            <i data-lucide="heart" class="w-4 h-4" :class="{'text-red-500 fill-current': false}"></i> 
                            <span class="text-xs" x-text="featured.meta.helpful_count"></span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- B. List Section - Only shows if there are articles -->
        <template x-if="articles.length > 0">
            <section class="px-5 py-4">
                <h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2">
                    Latest Guides <span class="text-[10px] bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full font-medium">Updated</span>
                </h3>
                
                <div class="space-y-4">
                    <template x-for="article in articles" :key="article.id">
                        <div @click="openArticle(article)" class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm flex gap-4 active:scale-[0.99] transition-transform cursor-pointer hover:border-purple-200">
                            
                            <div class="w-20 h-20 bg-gray-100 rounded-xl flex-shrink-0 overflow-hidden relative flex items-center justify-center">
                                <template x-if="article.thumbnail">
                                    <img :src="article.thumbnail" class="w-full h-full object-cover">
                                </template>
                                <template x-if="!article.thumbnail">
                                    <i data-lucide="book-open" class="w-8 h-8 text-gray-300"></i>
                                </template>
                            </div>

                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <h4 class="font-bold text-gray-800 text-sm leading-tight line-clamp-2" x-text="article.title"></h4>
                                    <span class="text-[9px] text-gray-400 bg-gray-50 px-1.5 py-0.5 rounded whitespace-nowrap ml-2" x-text="article.meta.read_time"></span>
                                </div>
                                <p class="text-xs text-gray-500 mt-1 line-clamp-2" x-html="article.excerpt || 'Click to read more...'"></p>
                                
                                <div class="flex items-center gap-4 mt-3 border-t border-gray-50 pt-2">
                                    <button @click.stop="markHelpful(article.id, $event)" class="flex items-center gap-1 text-gray-400 hover:text-red-500 transition-colors">
                                        <i data-lucide="heart" class="w-3 h-3"></i> 
                                        <span class="text-[10px]" x-text="article.meta.helpful_count"></span>
                                    </button>
                                    <span class="text-[10px] text-blue-500 bg-blue-50 px-2 rounded-full" x-text="article.meta.category"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </template>

        <!-- C. Totally Empty State -->
        <template x-if="!featured && articles.length === 0">
            <div class="flex flex-col items-center justify-center h-[60vh] text-center px-6">
                <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                    <i data-lucide="book" class="w-8 h-8 text-gray-300"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900">No guides yet</h3>
                <p class="text-sm text-gray-500 mt-2">We are crafting new tutorials for you. Check back soon!</p>
            </div>
        </template>

    </div>

    <!-- Article Reading Sheet -->
    <div x-show="isSheetOpen" x-transition.opacity class="fixed inset-0 bg-black/60 z-[60] backdrop-blur-sm" @click="closeArticle()" x-cloak></div>

    <div class="fixed bottom-0 left-0 w-full bg-white rounded-t-3xl z-[70] transform transition-transform duration-300 ease-out sm:max-w-md sm:left-1/2 sm:-translate-x-1/2 safe-bottom shadow-2xl h-[85vh] flex flex-col"
         :class="isSheetOpen ? 'translate-y-0' : 'translate-y-full'" x-cloak>
        
        <!-- Handle -->
        <div class="w-full flex justify-center pt-3 pb-1 flex-shrink-0 cursor-pointer" @click="closeArticle()">
            <div class="w-12 h-1.5 bg-gray-200 rounded-full"></div>
        </div>

        <template x-if="activeArticle">
            <div class="flex-1 flex flex-col h-full overflow-hidden">
                <div class="flex-1 overflow-y-auto px-6 pt-2 pb-20 relative">
                    
                    <span class="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded mb-3 inline-block" x-text="activeArticle.meta.category"></span>
                    <h1 class="text-2xl font-bold text-gray-900 mb-4 leading-tight" x-text="activeArticle.title"></h1>
                    
                    <div class="flex items-center gap-3 mb-6 pb-6 border-b border-gray-100">
                        <div class="w-10 h-10 rounded-full bg-gray-200 overflow-hidden">
                             <img :src="`https://api.dicebear.com/7.x/initials/svg?seed=${activeArticle.author}`" class="w-full h-full">
                        </div>
                        <div>
                            <p class="text-xs font-bold text-gray-900" x-text="activeArticle.author"></p>
                            <p class="text-[10px] text-gray-400" x-text="activeArticle.date_ago"></p>
                        </div>
                    </div>

                    <template x-if="activeArticle.meta.video_url">
                        <div class="mb-6 rounded-xl overflow-hidden shadow-sm border border-gray-200 aspect-video">
                            <iframe :src="activeArticle.meta.video_url.replace('watch?v=', 'embed/')" class="w-full h-full" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                        </div>
                    </template>

                    <div class="text-sm text-gray-600 leading-relaxed space-y-4 prose prose-sm max-w-none" x-html="activeArticle.content"></div>
                </div>

                <div class="border-t border-gray-100 p-4 pb-6 bg-white flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center gap-4">
                        <button @click="markHelpful(activeArticle.id, $event)" class="flex items-center gap-1.5 text-gray-500 hover:text-red-500 transition-colors">
                            <i data-lucide="heart" class="w-5 h-5"></i>
                            <span class="text-xs font-medium">Helpful (<span x-text="activeArticle.meta.helpful_count"></span>)</span>
                        </button>
                    </div>
                    <button class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="share-2" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
        </template>
    </div>

</div>

<?php include 'footer.php'; ?>