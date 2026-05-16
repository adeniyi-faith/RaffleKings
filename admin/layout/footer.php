    </div> <!-- Close flex wrapper from header.php -->

    <!-- Global Confirmation Modal Shell -->
    <div id="confirmation-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
        <div class="w-full max-w-md overflow-hidden rounded-3xl border border-white/10 bg-slate-900 shadow-2xl shadow-black/50">
            <div class="border-b border-white/10 px-6 py-5">
                <h3 class="text-lg font-bold text-white" id="modal-title">Confirm Action</h3>
            </div>
            <div class="px-6 py-5">
                <p class="text-sm leading-6 text-slate-400" id="modal-message">Are you sure you want to proceed?</p>
            </div>
            <div class="flex justify-end gap-3 border-t border-white/10 bg-slate-950/50 px-6 py-4">
                <button type="button" id="modal-cancel-btn" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 transition hover:bg-white/10">
                    Cancel
                </button>
                <button type="button" id="modal-confirm-btn" class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500">
                    Confirm
                </button>
            </div>
        </div>
    </div>

    <!-- Layout Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            function toggleSidebar() {
                const isClosed = sidebar.classList.contains('-translate-x-full');
                sidebar.classList.toggle('-translate-x-full', !isClosed);
                overlay.classList.toggle('hidden', !isClosed);
                document.body.classList.toggle('overflow-hidden', isClosed);
            }

            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', toggleSidebar);
            }
            if (overlay) {
                overlay.addEventListener('click', toggleSidebar);
            }

            window.showConfirmation = function(title, message, onConfirm) {
                const modal = document.getElementById('confirmation-modal');
                const titleEl = document.getElementById('modal-title');
                const messageEl = document.getElementById('modal-message');
                const cancelBtn = document.getElementById('modal-cancel-btn');
                const confirmBtn = document.getElementById('modal-confirm-btn');

                titleEl.textContent = title;
                messageEl.textContent = message;
                modal.classList.remove('hidden');
                modal.classList.add('flex');

                const newCancelBtn = cancelBtn.cloneNode(true);
                const newConfirmBtn = confirmBtn.cloneNode(true);
                cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
                confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

                function closeModal() {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }

                newCancelBtn.addEventListener('click', closeModal);
                newConfirmBtn.addEventListener('click', () => {
                    closeModal();
                    if (typeof onConfirm === 'function') {
                        onConfirm();
                    }
                });
            };
        });
    </script>
</body>
</html>
