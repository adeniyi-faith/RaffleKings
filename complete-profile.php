<?php
ob_start();
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');
if (!is_user_logged_in()) {
    header('Location: ' . (function_exists('rk_login_url_with_return') ? rk_login_url_with_return() : 'login.php?return=complete-profile.php'));
    exit;
}
$rk_completion = function_exists('rk_get_profile_completion_state') ? rk_get_profile_completion_state(get_current_user_id()) : ['missing' => []];
?>
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

    <script>
        window.RK_PROFILE_COMPLETION = <?php echo wp_json_encode($rk_completion); ?>;
        tailwind.config = { theme: { extend: { colors: { app: { primary: '#2563EB', primaryDark: '#1d4ed8' } } } } }
    </script>

    <link rel="stylesheet" href="assets/css/user/complete-profile.css">
</head>
<body class="bg-gray-50 flex items-center justify-center h-[100dvh] px-4">

    <div class="max-w-md w-full bg-white p-8 rounded-3xl shadow-xl text-center">

        <?php if (!empty($rk_completion['missing'])): ?>
            <div class="mb-5 text-left bg-blue-50 border border-blue-100 rounded-2xl p-4">
                <p class="text-xs font-black text-blue-700 uppercase tracking-wide mb-2">Profile checklist</p>
                <ul class="text-xs text-blue-900 space-y-1">
                    <?php foreach ($rk_completion['missing'] as $missing_item): ?>
                        <li>• <?php echo esc_html(ucwords(str_replace('_', ' ', $missing_item))); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

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

    <script src="assets/js/user/complete-profile.js"></script>
</body>
</html>
