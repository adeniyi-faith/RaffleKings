<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Complete Profile - RaffleKings</title>
    
    <!-- *** SECURITY PATCH: META TAGS *** -->
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- *** SECURITY PATCH: CENTRALIZED CONFIG WITH CACHE BUSTING *** -->
    <script src="config.js?v=<?php echo time(); ?>"></script>
    <script src="watchdog.js"></script>
    <script src="analytics.js"></script>

    <!-- Auth Guard -->
    <script>
        const token = localStorage.getItem('token');
        if(!token) window.location.href = 'login.php'; 

        tailwind.config = { theme: { extend: { colors: { app: { primary: '#2563EB', primaryDark: '#1d4ed8' } } } } }
    </script>

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F3F4F6; }
        @keyframes shine { 0% { background-position: -100% 0; } 100% { background-position: 200% 0; } }
        .gold-ring { background: linear-gradient(45deg, #F59E0B, #FDE68A, #D97706, #F59E0B); background-size: 200% auto; animation: shine 3s linear infinite; padding: 4px; border-radius: 50%; }
        select { font-size: 16px !important; }
    </style>
</head>
<body class="bg-gray-50 flex items-center justify-center h-[100dvh] px-4">

    <div class="max-w-md w-full bg-white p-8 rounded-3xl shadow-xl text-center">
        
        <div class="mb-4">
            <h1 class="text-2xl font-extrabold text-gray-900 mb-2">One Last Thing!</h1>
            <p class="text-sm text-gray-500">Complete your profile to start winning.</p>
        </div>

        <!-- Warning Box -->
        <div class="bg-orange-50 border border-orange-100 rounded-xl p-3 mb-6 flex gap-3 text-left">
            <div class="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center flex-shrink-0 text-orange-600">
                <i data-lucide="alert-triangle" class="w-4 h-4"></i>
            </div>
            <div>
                <h4 class="font-bold text-orange-900 text-xs">Prize Verification</h4>
                <p class="text-[10px] text-orange-700 leading-tight mt-0.5">Users without a verified profile picture and state will <strong>NOT</strong> be awarded prizes.</p>
            </div>
        </div>

        <form id="complete-form" onsubmit="handleCompletion(event)">
            
            <!-- 1. State Selection -->
            <div class="mb-6 text-left">
                <label class="text-xs font-bold text-gray-500 uppercase tracking-wide ml-1 mb-1 block">State of Residence</label>
                <div class="relative">
                    <select id="input-state" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-app-primary/20 text-gray-800 font-medium shadow-sm appearance-none cursor-pointer" required>
                        <option value="" disabled selected>Select State...</option>
                        <option value="Abia">Abia</option><option value="Adamawa">Adamawa</option><option value="Akwa Ibom">Akwa Ibom</option><option value="Anambra">Anambra</option><option value="Bauchi">Bauchi</option><option value="Bayelsa">Bayelsa</option><option value="Benue">Benue</option><option value="Borno">Borno</option><option value="Cross River">Cross River</option><option value="Delta">Delta</option><option value="Ebonyi">Ebonyi</option><option value="Edo">Edo</option><option value="Ekiti">Ekiti</option><option value="Enugu">Enugu</option><option value="FCT - Abuja">FCT - Abuja</option><option value="Gombe">Gombe</option><option value="Imo">Imo</option><option value="Jigawa">Jigawa</option><option value="Kaduna">Kaduna</option><option value="Kano">Kano</option><option value="Katsina">Katsina</option><option value="Kebbi">Kebbi</option><option value="Kogi">Kogi</option><option value="Kwara">Kwara</option><option value="Lagos">Lagos</option><option value="Nasarawa">Nasarawa</option><option value="Niger">Niger</option><option value="Ogun">Ogun</option><option value="Ondo">Ondo</option><option value="Osun">Osun</option><option value="Oyo">Oyo</option><option value="Plateau">Plateau</option><option value="Rivers">Rivers</option><option value="Sokoto">Sokoto</option><option value="Taraba">Taraba</option><option value="Yobe">Yobe</option><option value="Zamfara">Zamfara</option>
                    </select>
                    <i data-lucide="chevron-down" class="absolute right-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none"></i>
                </div>
            </div>

            <!-- 2. Upload Circle -->
            <div class="flex justify-center mb-8">
                <div class="relative group cursor-pointer active:scale-95 transition-transform" onclick="document.getElementById('file-upload').click()">
                    <div class="gold-ring w-32 h-32 flex items-center justify-center shadow-xl shadow-orange-500/20">
                        <div class="w-full h-full bg-white rounded-full overflow-hidden border-4 border-white flex items-center justify-center relative">
                            <img id="preview-img" src="" class="w-full h-full object-cover hidden">
                            <div id="upload-placeholder" class="text-center">
                                <div class="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-1">
                                    <i data-lucide="camera" class="w-5 h-5 text-gray-400"></i>
                                </div>
                                <span class="text-[9px] text-gray-400 font-bold uppercase tracking-wide">Photo</span>
                            </div>
                        </div>
                    </div>
                    <div class="absolute bottom-1 right-1 bg-app-primary text-white w-8 h-8 rounded-full flex items-center justify-center border-4 border-white shadow-md z-10">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                    </div>
                    <input type="file" id="file-upload" class="hidden" accept="image/*" onchange="previewImage(this)" required>
                </div>
            </div>

            <div class="space-y-3">
                <button type="submit" id="save-btn" disabled class="w-full bg-app-primary text-white py-4 rounded-xl font-bold shadow-lg disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center justify-center gap-2">
                    Save Profile <i data-lucide="check-circle" class="w-4 h-4"></i>
                </button>
            </div>
            
        </form>

    </div>

    <script>
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

            // Get Token (Required)
            const token = localStorage.getItem('token');
            if (!token) {
                alert("Session expired. Please login again.");
                window.location.href = 'login.php';
                return;
            }

            try {
                // Use Centralized Config
                const endpoint = (typeof API_CONFIG !== 'undefined') ? API_CONFIG.PROFILE : 'https://api.rafflekings.com.ng/wp-json/raffle/v1/profile';

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 
                        'Authorization': `Bearer ${token}`
                    },
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
    </script>
</body>
</html>