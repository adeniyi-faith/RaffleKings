        lucide.createIcons();
        const fileInput = document.getElementById('file-upload');
        const stateInput = document.getElementById('input-state');
        const saveBtn = document.getElementById('save-btn');

        // Check validity on every change
        function checkValidity() {
            if (fileInput.files.length > 0 && stateInput.value !== "") {
                saveBtn.disabled = false;
                saveBtn.classList.remove('disabled:opacity-50', 'disabled:cursor-not-allowed');
            } else {
                saveBtn.disabled = true;
                saveBtn.classList.add('disabled:opacity-50', 'disabled:cursor-not-allowed');
            }
        }

        stateInput.addEventListener('change', checkValidity);

        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('upload-placeholder').classList.add('hidden');
                    const img = document.getElementById('preview-img');
                    img.src = e.target.result;
                    img.classList.remove('hidden');
                    checkValidity(); // Re-check
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function handleCompletion(e) {
            e.preventDefault();

            if (!fileInput.files.length || !stateInput.value) {
                alert("Both State and Photo are required.");
                return;
            }

            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="animate-spin mr-2"><i data-lucide="loader-2" class="w-4 h-4"></i></span> Saving...';
            lucide.createIcons();

            const formData = new FormData();
            formData.append('profile_image', fileInput.files[0]);
            formData.append('state', stateInput.value);

            try {
                // Use Centralized Config
                const endpoint = (typeof API_CONFIG !== 'undefined') ? API_CONFIG.PROFILE_UPDATE : 'ajax-router.php?action=update_profile';

                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                const data = await response.json();

                if (response.ok) {
                    if(data.avatar) localStorage.setItem('user_avatar_url', data.avatar);

                    // *** RECOVERY LOGIC: CHECK FOR PENDING CHECKOUT ***
                    const pending = localStorage.getItem('pendingCheckout');
                    if (pending) {
                        try {
                            const item = JSON.parse(pending);
                            const numbersStr = Array.isArray(item.numbers) ? item.numbers.join(',') : item.numbers;
                            const amount = item.amount || item.price;
                            const tickets = item.tickets || item.qty;
                            const rId = item.raffleId || item.raffle_id || 0;

                            const url = `checkout.php?amount=${amount}&tickets=${tickets}&numbers=${numbersStr}&raffle_id=${rId}`;

                            localStorage.removeItem('pendingCheckout');
                            window.location.href = url;
                            return;
                        } catch(parseErr) {
                            console.error("Invalid pending checkout data", parseErr);
                        }
                    }

                    // Default Fallback
                    window.location.href = 'profile.php';
                } else {
                    throw new Error(data.message || 'Update failed');
                }
            } catch (e) {
                alert(e.message || "An error occurred");
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
                lucide.createIcons();
            }
        }
