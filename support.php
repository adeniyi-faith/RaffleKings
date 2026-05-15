<?php include 'header.php'; ?>

<!-- Scrollable Content Area -->
<div class="flex-1 overflow-y-auto no-scrollbar pb-28 bg-gray-50 relative">

    <!-- Header -->
    <div class="bg-white px-5 pt-2 pb-4 border-b border-gray-100 sticky top-0 z-40 shadow-sm">
        <h2 class="text-xl font-bold text-gray-900">Help & Support</h2>
        <p class="text-xs text-gray-500">We are here to help you win.</p>
    </div>

    <!-- 1. Quick Tutorial Access -->
    <section class="p-5 pb-2">
        <a href="tutorials.php" class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-2xl p-5 text-white shadow-lg shadow-blue-500/20 relative overflow-hidden block group active:scale-[0.98] transition-transform">
            <div class="absolute right-0 bottom-0 w-24 h-24 bg-white/10 rounded-full blur-2xl translate-y-1/4 translate-x-1/4"></div>
            
            <div class="relative z-10 flex items-center justify-between">
                <div>
                    <span class="bg-white/20 text-white text-[9px] font-bold px-2 py-0.5 rounded mb-2 inline-block">NEW USER?</span>
                    <h3 class="font-bold text-lg leading-tight">How to Play & Win</h3>
                    <p class="text-xs text-blue-100 mt-1">Read the 3-step guide</p>
                </div>
                <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                    <i data-lucide="book-open" class="w-5 h-5 text-white"></i>
                </div>
            </div>
        </a>
    </section>

    <!-- 2. Support History (Tickets) -->
    <section class="px-5 pt-2">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-gray-900">Your Conversations</h3>
            <!-- Inline Open Ticket Button -->
            <button onclick="openSupportSheet()" class="text-[10px] font-bold text-app-primary bg-blue-50 px-3 py-1.5 rounded-full flex items-center gap-1 active:bg-blue-100 transition-colors">
                <i data-lucide="plus" class="w-3 h-3"></i> New Ticket
            </button>
        </div>

        <div class="space-y-3" id="ticket-list">
            
            <!-- Ticket 1: Answered -->
            <div onclick="toggleTicket('ticket-1')" class="bg-white border border-gray-100 p-4 rounded-xl shadow-sm active:bg-gray-50 transition-colors cursor-pointer">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-green-500"></div>
                        <h4 class="text-sm font-bold text-gray-800">Withdrawal Delay</h4>
                    </div>
                    <span class="text-[10px] text-gray-400">2 hrs ago</span>
                </div>
                <p class="text-xs text-gray-500 line-clamp-1">I requested a withdrawal of ₦5,000 but haven't received it.</p>
                
                <!-- Expanded Content (Hidden by default) -->
                <div id="ticket-1" class="hidden mt-3 pt-3 border-t border-gray-50">
                    <div class="bg-blue-50 rounded-lg p-3 mb-2">
                        <p class="text-[10px] font-bold text-blue-800 mb-1">Support Team</p>
                        <p class="text-xs text-blue-700">Hi Kingsley, we verified your transaction. It was processed at 10:45 AM. Please check your GTBank app again.</p>
                    </div>
                    <div class="text-right">
                        <button class="text-[10px] font-bold text-gray-400 border border-gray-200 px-3 py-1 rounded-full hover:bg-gray-50">Reply</button>
                    </div>
                </div>
            </div>

            <!-- Ticket 2: Pending -->
            <div class="bg-white border border-gray-100 p-4 rounded-xl shadow-sm opacity-70">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-yellow-500"></div>
                        <h4 class="text-sm font-bold text-gray-800">Scholarship Verification</h4>
                    </div>
                    <span class="text-[10px] text-gray-400">Yesterday</span>
                </div>
                <p class="text-xs text-gray-500">My niece doesn't have a Matric Number yet...</p>
                <div class="mt-2 inline-block bg-yellow-50 text-yellow-700 text-[9px] font-bold px-2 py-0.5 rounded">
                    Awaiting Reply
                </div>
            </div>

        </div>
    </section>

</div>

<!-- Floating "Ask Question" Button (FAB) -->
<button onclick="openSupportSheet()" class="fixed bottom-24 right-5 w-14 h-14 bg-gray-900 rounded-full shadow-xl shadow-gray-900/30 flex items-center justify-center text-white z-30 ripple-container active:scale-90 transition-transform">
    <i data-lucide="message-square-plus" class="w-6 h-6"></i>
</button>

<!-- New Ticket Bottom Sheet -->
<div id="support-overlay" onclick="closeSupportSheet()" class="fixed inset-0 bg-black/60 z-50 hidden transition-opacity opacity-0 backdrop-blur-sm"></div>

<div id="support-sheet" class="fixed bottom-0 left-0 w-full bg-white rounded-t-3xl z-50 transform translate-y-full transition-transform duration-300 ease-out sm:max-w-md sm:left-1/2 sm:-translate-x-1/2 safe-bottom shadow-2xl">
    
    <div class="w-full flex justify-center pt-3 pb-1" onclick="closeSupportSheet()">
        <div class="w-12 h-1.5 bg-gray-200 rounded-full"></div>
    </div>

    <div class="p-6 pt-2">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Open New Ticket</h3>
        
        <form onsubmit="submitTicket(event)">
            <div class="space-y-4">
                <div>
                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wide block mb-2">Issue Type</label>
                    <select id="ticket-category" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-app-primary/20">
                        <option value="General Inquiry">General Inquiry</option>
                        <option value="Withdrawal Issue">Withdrawal / Deposit</option>
                        <option value="Claiming Prize">Claiming a Prize</option>
                        <option value="Bug Report">Report a Bug</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wide block mb-2">Message</label>
                    <textarea id="ticket-msg" rows="4" placeholder="Describe your issue in detail..." class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-app-primary/20"></textarea>
                </div>
            </div>

            <button type="submit" class="w-full mt-6 bg-app-primary text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
                Submit Ticket <i data-lucide="send" class="w-4 h-4"></i>
            </button>
        </form>
    </div>
</div>

<script>
    function toggleTicket(id) {
        const el = document.getElementById(id);
        if (el.classList.contains('hidden')) {
            el.classList.remove('hidden');
        } else {
            el.classList.add('hidden');
        }
    }

    const overlay = document.getElementById('support-overlay');
    const sheet = document.getElementById('support-sheet');

    function openSupportSheet() {
        overlay.classList.remove('hidden');
        setTimeout(() => {
            overlay.classList.remove('opacity-0');
            sheet.classList.remove('translate-y-full');
            if(window.innerWidth >= 640) sheet.classList.remove('sm:translate-y-[120%]');
        }, 10);
    }

    function closeSupportSheet() {
        overlay.classList.add('opacity-0');
        sheet.classList.add('translate-y-full');
        setTimeout(() => {
            overlay.classList.add('hidden');
        }, 300);
    }

    function submitTicket(e) {
        e.preventDefault();
        const msg = document.getElementById('ticket-msg').value;
        const category = document.getElementById('ticket-category').value;
        
        if(!msg) {
            alert("Please describe your issue.");
            return;
        }

        // Simulate submission
        closeSupportSheet();
        
        // Optimistically add to list with correct category title
        const list = document.getElementById('ticket-list');
        const newTicketId = 'ticket-' + Date.now();
        
        const newTicket = `
            <div onclick="toggleTicket('${newTicketId}')" class="bg-white border border-blue-200 p-4 rounded-xl shadow-sm animate-pulse cursor-pointer">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-blue-500 animate-ping"></div>
                        <h4 class="text-sm font-bold text-gray-800">${category}</h4>
                    </div>
                    <span class="text-[10px] text-gray-400">Just now</span>
                </div>
                <p class="text-xs text-gray-500 line-clamp-1">${msg}</p>
                
                <!-- Hidden Details -->
                <div id="${newTicketId}" class="hidden mt-3 pt-2 border-t border-gray-50">
                    <div class="mt-2 inline-block bg-blue-50 text-blue-700 text-[9px] font-bold px-2 py-0.5 rounded">Sending to support...</div>
                    <p class="text-xs text-gray-600 mt-2">${msg}</p>
                </div>
            </div>
        `;
        list.insertAdjacentHTML('afterbegin', newTicket);
        
        // Reset form
        document.getElementById('ticket-msg').value = '';
    }
</script>

<?php include 'footer.php'; ?>