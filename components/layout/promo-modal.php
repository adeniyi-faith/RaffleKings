<div id="rk-promo-modal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 backdrop-blur-sm p-4">
        <div class="relative w-full max-w-sm bg-white rounded-3xl overflow-hidden shadow-2xl transform transition-all scale-95" id="rk-modal-card">
            <div class="bg-gradient-to-r from-red-600 to-red-500 p-4 sm:p-5 text-center relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-full opacity-10" style="background-image: url('data:image/svg+xml,%3Csvg width=\'20\' height=\'20\' viewBox=\'0 0 20 20\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'1\' fill-rule=\'evenodd\'%3E%3Ccircle cx=\'3\' cy=\'3\' r=\'3\'/%3E%3Ccircle cx=\'13\' cy=\'13\' r=\'3\'/%3E%3C/g%3E%3C/svg%3E');"></div>
                <h2 class="text-xl sm:text-2xl font-black text-white italic uppercase relative z-10">Secret Offer!</h2>
                <p class="text-white/90 text-xs sm:text-sm font-medium relative z-10">Spin to reveal your welcome gift</p>
                <button id="rk-close-btn" class="absolute top-2 right-2 text-white/50 hover:text-white" onclick="RKPromo.closeModal()">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="p-5 sm:p-8 flex flex-col items-center justify-center bg-yellow-50 relative w-full">
                <div class="absolute inset-3 border-4 border-dashed border-yellow-400 rounded-2xl pointer-events-none opacity-50"></div>
                <div class="relative my-4 sm:my-6 filter drop-shadow-xl mx-auto flex items-center justify-center w-[260px] h-[260px] sm:w-[300px] sm:h-[300px]">
                    <canvas id="rk-wheel-canvas" width="600" height="600" class="w-full h-full block"></canvas>
                    <div class="absolute top-1/2 left-1/2 w-12 h-12 bg-white rounded-full shadow-lg flex items-center justify-center z-10" style="transform: translate(-50%, -50%);">
                        <i data-lucide="star" class="w-6 h-6 text-yellow-500 fill-current" style="display: block;"></i>
                    </div>
                    <div class="absolute left-1/2 z-20 filter drop-shadow-md" style="top: -16px; transform: translateX(-50%);">
                        <div class="w-0 h-0 border-l-[15px] border-l-transparent border-r-[15px] border-r-transparent border-t-[30px] border-t-yellow-400"></div>
                    </div>
                </div>
                <div class="w-full px-2 mt-2 z-10">
                    <button id="rk-spin-btn" onclick="RKPromo.spin()" class="rk-spin-btn w-full text-red-900 font-black text-lg sm:text-xl py-3.5 rounded-full uppercase tracking-wide">
                        Spin to Win
                    </button>
                    <div id="rk-claim-area" class="hidden text-center">
                        <p class="text-[10px] sm:text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">CONGRATULATIONS!</p>
                        <h3 class="text-3xl sm:text-4xl font-black text-green-600 leading-none mb-3" id="rk-win-label">...</h3>
                        <div class="bg-green-100 text-green-800 text-[10px] font-bold py-1 px-3 rounded-lg mb-4 inline-block border border-green-200">
                            Enough for 2 Free Tickets!
                        </div>
                        <button onclick="RKPromo.claim()" class="rk-claim-btn w-full bg-green-600 text-white font-bold text-lg py-3.5 rounded-full hover:bg-green-700 flex items-center justify-center gap-2">
                            CLAIM ₦300 NOW <i data-lucide="chevron-right" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    <canvas id="rk-confetti" class="fixed inset-0 pointer-events-none z-[110]"></canvas>
</div>
