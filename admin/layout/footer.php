    </div> <!-- Close flex wrapper from header.php -->

    <!-- Global Confirmation Modal Shell -->
    <div id="confirmation-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl w-11/12 max-w-md overflow-hidden transform transition-all">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900" id="modal-title">Confirm Action</h3>
            </div>
            <div class="px-6 py-4">
                <p class="text-sm text-gray-500" id="modal-message">Are you sure you want to proceed?</p>
            </div>
            <div class="px-6 py-3 bg-gray-50 flex justify-end space-x-3">
                <button type="button" id="modal-cancel-btn" class="px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none">
                    Cancel
                </button>
                <button type="button" id="modal-confirm-btn" class="px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none">
                    Confirm
                </button>
            </div>
        </div>
    </div>

    <!-- Layout Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Mobile Sidebar Toggle
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            function toggleSidebar() {
                const isClosed = sidebar.classList.contains('-translate-x-full');
                if (isClosed) {
                    sidebar.classList.remove('-translate-x-full');
                    overlay.classList.remove('hidden');
                    document.body.classList.add('overflow-hidden');
                } else {
                    sidebar.classList.add('-translate-x-full');
                    overlay.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden');
                }
            }

            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', toggleSidebar);
            }
            if (overlay) {
                overlay.addEventListener('click', toggleSidebar);
            }

            // Global Confirmation Modal Logic
            window.showConfirmation = function(title, message, onConfirm) {
                const modal = document.getElementById('confirmation-modal');
                const titleEl = document.getElementById('modal-title');
                const messageEl = document.getElementById('modal-message');
                const cancelBtn = document.getElementById('modal-cancel-btn');
                const confirmBtn = document.getElementById('modal-confirm-btn');

                titleEl.textContent = title;
                messageEl.textContent = message;
                modal.classList.remove('hidden');

                // Cleanup previous listeners
                const newCancelBtn = cancelBtn.cloneNode(true);
                const newConfirmBtn = confirmBtn.cloneNode(true);
                cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
                confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

                newCancelBtn.addEventListener('click', () => {
                    modal.classList.add('hidden');
                });

                newConfirmBtn.addEventListener('click', () => {
                    modal.classList.add('hidden');
                    if (typeof onConfirm === 'function') {
                        onConfirm();
                    }
                });
            };
        });
    </script>
</body>
</html>