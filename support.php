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

        <div id="ticket-skeleton" class="space-y-3">
            <div class="bg-white border border-gray-100 p-4 rounded-xl h-20 animate-pulse"></div>
            <div class="bg-white border border-gray-100 p-4 rounded-xl h-20 animate-pulse"></div>
        </div>

        <div id="no-tickets" class="hidden text-center py-10 text-gray-400">
            <i data-lucide="inbox" class="w-10 h-10 mx-auto mb-2"></i>
            <p class="text-xs">No conversations yet. Tap "New Ticket" if you need help.</p>
        </div>

        <div class="space-y-3 hidden" id="ticket-list"></div>
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

        <div id="ticket-form-error" class="hidden mb-3 text-xs font-bold text-red-600 bg-red-50 p-2 rounded-lg"></div>

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

            <button type="submit" id="ticket-submit-btn" class="w-full mt-6 bg-app-primary text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2">
                Submit Ticket <i data-lucide="send" class="w-4 h-4"></i>
            </button>
        </form>
    </div>
</div>

<script src="config.js"></script>
<script>
    lucide.createIcons();

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

    function timeAgo(dateStr) {
        // Server times are stored/returned in WordPress local time (not UTC).
        const then = new Date(dateStr.replace(' ', 'T'));
        const diffMs = Date.now() - then.getTime();
        const mins = Math.floor(diffMs / 60000);
        if (mins < 1) return 'Just now';
        if (mins < 60) return mins + 'm ago';
        const hrs = Math.floor(mins / 60);
        if (hrs < 24) return hrs + 'h ago';
        const days = Math.floor(hrs / 24);
        return days + 'd ago';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function statusDot(status) {
        if (status === 'resolved' || status === 'closed') return 'bg-gray-400';
        if (status === 'open') return 'bg-yellow-500';
        if (status === 'answered') return 'bg-blue-500';
        return 'bg-green-500';
    }

    function renderTicketCard(t) {
        const wrapper = document.createElement('div');
        wrapper.className = 'bg-white border border-gray-100 p-4 rounded-xl shadow-sm active:bg-gray-50 transition-colors cursor-pointer';
        wrapper.dataset.ticketId = t.id;
        wrapper.innerHTML = `
            <div class="flex justify-between items-start mb-2">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full ${statusDot(t.status)}"></div>
                    <h4 class="text-sm font-bold text-gray-800">${escapeHtml(t.subject || t.category)}</h4>
                </div>
                <span class="text-[10px] text-gray-400">${timeAgo(t.updated_at)}</span>
            </div>
            <p class="text-xs text-gray-500 line-clamp-1">${escapeHtml(t.last_message)}</p>
            <div id="ticket-body-${t.id}" class="hidden mt-3 pt-3 border-t border-gray-50"></div>
        `;
        wrapper.addEventListener('click', () => toggleTicket(t.id));
        return wrapper;
    }

    async function apiFetch(url, options = {}) {
        const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, options));
        if (res.status === 401) {
            localStorage.clear();
            window.location.href = 'login.php';
            throw new Error('Not logged in');
        }
        const payload = await res.json();
        if (!res.ok || payload.success === false) {
            throw new Error(payload.message || 'Request failed');
        }
        return payload.data !== undefined ? payload.data : payload;
    }

    let ticketsCache = [];

    async function loadTickets() {
        const skeleton = document.getElementById('ticket-skeleton');
        const noTickets = document.getElementById('no-tickets');
        const list = document.getElementById('ticket-list');

        try {
            const tickets = await apiFetch(API_CONFIG.SUPPORT_TICKETS);
            ticketsCache = Array.isArray(tickets) ? tickets : [];

            skeleton.classList.add('hidden');
            list.innerHTML = '';

            if (ticketsCache.length === 0) {
                noTickets.classList.remove('hidden');
                list.classList.add('hidden');
                return;
            }

            noTickets.classList.add('hidden');
            list.classList.remove('hidden');
            ticketsCache.forEach(t => list.appendChild(renderTicketCard(t)));
        } catch (err) {
            skeleton.classList.add('hidden');
            noTickets.classList.remove('hidden');
            noTickets.querySelector('p').textContent = 'Could not load your tickets. Pull to refresh.';
        }
    }

    async function toggleTicket(id) {
        const body = document.getElementById('ticket-body-' + id);
        if (!body) return;

        if (!body.classList.contains('hidden')) {
            body.classList.add('hidden');
            return;
        }

        body.classList.remove('hidden');
        body.innerHTML = '<p class="text-xs text-gray-400">Loading conversation...</p>';

        try {
            const ticket = await apiFetch(API_CONFIG.SUPPORT_TICKET + '&id=' + encodeURIComponent(id));
            body.innerHTML = '';

            (ticket.messages || []).forEach(m => {
                const isAdmin = m.sender_type === 'admin';
                const bubble = document.createElement('div');
                bubble.className = (isAdmin ? 'bg-blue-50' : 'bg-gray-50') + ' rounded-lg p-3 mb-2';
                bubble.innerHTML = `
                    <p class="text-[10px] font-bold ${isAdmin ? 'text-blue-800' : 'text-gray-500'} mb-1">${isAdmin ? 'Support Team' : 'You'}</p>
                    <p class="text-xs ${isAdmin ? 'text-blue-700' : 'text-gray-700'}">${escapeHtml(m.message)}</p>
                `;
                body.appendChild(bubble);
            });

            const replyRow = document.createElement('div');
            replyRow.className = 'flex gap-2 mt-2';
            replyRow.innerHTML = `
                <input type="text" placeholder="Type a reply..." class="flex-1 bg-gray-50 border border-gray-200 rounded-full px-3 py-2 text-xs outline-none focus:ring-2 focus:ring-app-primary/20">
                <button class="text-[10px] font-bold text-white bg-app-primary px-4 py-2 rounded-full">Send</button>
            `;
            const input = replyRow.querySelector('input');
            const sendBtn = replyRow.querySelector('button');
            sendBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const msg = input.value.trim();
                if (!msg) return;
                sendBtn.disabled = true;
                try {
                    await apiFetch(API_CONFIG.REPLY_SUPPORT_TICKET, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id, message: msg })
                    });
                    input.value = '';
                    body.classList.add('hidden');
                    await loadTickets();
                    toggleTicket(id);
                } catch (err) {
                    alert(err.message || 'Could not send reply.');
                } finally {
                    sendBtn.disabled = false;
                }
            });
            replyRow.addEventListener('click', (e) => e.stopPropagation());
            body.appendChild(replyRow);
        } catch (err) {
            body.innerHTML = '<p class="text-xs text-red-500">Could not load this conversation.</p>';
        }
    }

    async function submitTicket(e) {
        e.preventDefault();
        const msg = document.getElementById('ticket-msg').value.trim();
        const category = document.getElementById('ticket-category').value;
        const errBox = document.getElementById('ticket-form-error');
        const btn = document.getElementById('ticket-submit-btn');

        errBox.classList.add('hidden');

        if (!msg) {
            errBox.textContent = 'Please describe your issue.';
            errBox.classList.remove('hidden');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = 'Sending...';

        try {
            await apiFetch(API_CONFIG.CREATE_SUPPORT_TICKET, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category, message: msg })
            });

            closeSupportSheet();
            document.getElementById('ticket-msg').value = '';
            await loadTickets();
        } catch (err) {
            errBox.textContent = err.message || 'Could not submit ticket. Please try again.';
            errBox.classList.remove('hidden');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Submit Ticket <i data-lucide="send" class="w-4 h-4"></i>';
            lucide.createIcons();
        }
    }

    document.addEventListener('DOMContentLoaded', loadTickets);
</script>

<?php include 'footer.php'; ?>
