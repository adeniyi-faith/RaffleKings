    </div> <!-- Close flex wrapper from header.php -->

    <!-- Global Confirmation Modal Shell -->
    <div id="confirmation-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-navy-900/40 p-4 backdrop-blur-sm">
        <div class="w-full max-w-md overflow-hidden rounded-[24px] border border-gray-100 bg-white shadow-xl shadow-navy-900/5">
            <div class="border-b border-gray-100 px-6 py-5">
                <h3 class="text-lg font-bold text-navy-900" id="modal-title">Confirm Action</h3>
            </div>
            <div class="px-6 py-5">
                <p class="text-sm leading-6 text-gray-600" id="modal-message">Are you sure you want to proceed?</p>
            </div>
            <div class="flex justify-end gap-3 border-t border-gray-50 bg-gray-50/50 px-6 py-4">
                <button type="button" id="modal-cancel-btn" class="rounded-2xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-bold text-gray-700 transition hover:bg-gray-50 hover:text-gray-900">
                    Cancel
                </button>
                <button type="button" id="modal-confirm-btn" class="rounded-2xl bg-navy-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-navy-800 shadow-sm shadow-navy-900/20">
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
