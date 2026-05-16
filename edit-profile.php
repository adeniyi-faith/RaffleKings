<?php
/**
 * Edit Profile - SSR & Local Proxy Architecture
 * Zero-latency load, native WordPress integration.
 */

define('WP_USE_THEMES', false);
// Adjust this path if your wp-load.php resides elsewhere in your grand estate
require_once(__DIR__ . '/wp/wp-load.php');

// 1. AUTH GUARD - Banish the uninvited
if (!is_user_logged_in()) {
    header('Location: ' . (function_exists('rk_login_url_with_return') ? rk_login_url_with_return() : 'login.php'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ==========================================
// 2. THE LOCAL PROXY (POST INTERCEPT)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    header('Content-Type: application/json');

    $first_name   = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name    = sanitize_text_field($_POST['last_name'] ?? '');
    $display_name = sanitize_text_field($_POST['display_name'] ?? '');
    $email        = sanitize_email($_POST['email'] ?? '');
    $phone        = sanitize_text_field($_POST['phone'] ?? '');
    $state        = sanitize_text_field($_POST['state'] ?? '');
    $password     = $_POST['password'] ?? '';

    // Update Primary User Object
    $user_data = [
        'ID'           => $user_id,
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'display_name' => $display_name,
        'user_email'   => $email,
    ];
    $update_result = wp_update_user($user_data);

    if (is_wp_error($update_result)) {
        echo json_encode(['success' => false, 'message' => $update_result->get_error_message()]);
        exit;
    }

    // Update Extended Meta
    update_user_meta($user_id, 'phone', $phone);
    update_user_meta($user_id, 'state', $state);

    // Handle Password Change Elegantly
    if (!empty($password)) {
        wp_set_password($password, $user_id);
        // wp_set_password invalidates the session; we must invite the user back in immediately
        wp_set_auth_cookie($user_id, true);
    }

    // Handle Profile Image Upload
    $avatar_url = get_user_meta($user_id, 'profile_pic_url', true);
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $attachment_id = media_handle_upload('profile_image', 0);
        if (!is_wp_error($attachment_id)) {
            $avatar_url = wp_get_attachment_url($attachment_id);
            update_user_meta($user_id, 'profile_pic_url', $avatar_url);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Profile Updated Successfully!',
        'avatar'  => $avatar_url
    ]);
    exit;
}

// ==========================================
// 3. ZERO-LATENCY SSR DATA PREPARATION
// ==========================================
$ssr_first_name   = $current_user->first_name;
$ssr_last_name    = $current_user->last_name;
$ssr_display_name = $current_user->display_name;
$ssr_email        = $current_user->user_email;
$ssr_phone        = get_user_meta($user_id, 'phone', true);
$ssr_state        = get_user_meta($user_id, 'state', true);

// Fetch avatar or fall back to an agreeable Dicebear initial
$saved_avatar = get_user_meta($user_id, 'profile_pic_url', true);
$ssr_avatar   = $saved_avatar ? $saved_avatar : 'https://api.dicebear.com/7.x/initials/svg?seed=' . urlencode($ssr_display_name);

// Now we may present the visual interface
include 'header.php';
?>

<!-- No more rudimentary localStorage auth guards needed here; PHP handles the door -->

<script>
    function editProfile() {
        return {
            form: {
                // Injected directly from our gracious server
                first_name: <?= json_encode($ssr_first_name) ?>,
                last_name: <?= json_encode($ssr_last_name) ?>,
                display_name: <?= json_encode($ssr_display_name) ?>,
                email: <?= json_encode($ssr_email) ?>,
                phone: <?= json_encode($ssr_phone) ?>,
                state: <?= json_encode($ssr_state) ?>,
                password: '',
                avatar: <?= json_encode($ssr_avatar) ?>
            },
            isSaving: false,
            message: '',
            isError: false,
            imageFile: null,

            initForm() {
                // Initialize icons gracefully
                this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
                // Note: The fetchRemoteProfile() call has been banished!
                // The data is already here, perfectly composed.
            },

            previewImage(e) {
                const file = e.target.files[0];
                if (file) {
                    this.imageFile = file;
                    const reader = new FileReader();
                    reader.onload = (e) => { this.form.avatar = e.target.result; };
                    reader.readAsDataURL(file);
                }
            },

            async saveProfile() {
                this.isSaving = true;
                this.message = '';

                const formData = new FormData();
                // Add our Local Proxy action
                formData.append('action', 'update_profile');

                Object.keys(this.form).forEach(key => {
                    if(key !== 'avatar' && this.form[key]) formData.append(key, this.form[key]);
                });

                if (this.imageFile) formData.append('profile_image', this.imageFile);

                try {
                    // We dispatch the coach to our very own estate (window.location.href)
                    const res = await fetch(window.location.href.split('?')[0], {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();

                    if(res.ok && data.success) {
                        this.isError = false;
                        this.message = data.message;

                        // Update cache merely for the benefit of other legacy pages
                        this.updateCache({
                            ...this.form,
                            avatar: data.avatar || this.form.avatar
                        });

                        setTimeout(() => window.location.href = 'profile.php', 1000);
                    } else {
                        throw new Error(data.message || 'An unfortunate error occurred whilst updating.');
                    }
                } catch(e) {
                    this.isError = true;
                    this.message = e.message;
                } finally {
                    this.isSaving = false;
                }
            },

            updateCache(data) {
                if(data.first_name) localStorage.setItem('user_first_name', data.first_name);
                if(data.last_name) localStorage.setItem('user_last_name', data.last_name);
                if(data.display_name) {
                    localStorage.setItem('user_display_name', data.display_name);
                    localStorage.setItem('user_nicename', data.display_name);
                }
                if(data.email) localStorage.setItem('user_email', data.email);
                if(data.phone) localStorage.setItem('user_phone', data.phone);
                if(data.state) localStorage.setItem('user_state', data.state);
                if(data.avatar) localStorage.setItem('user_avatar_url', data.avatar);
            }
        }
    }
</script>

<style>
    /* Prevent FOUC */
    [x-cloak] { display: none !important; }

    /* Utility */
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    /* Safe Area Spacing for iOS */
    .safe-pb { padding-bottom: env(safe-area-inset-bottom); }
</style>

<!-- Main Container -->
<div x-data="editProfile()" x-init="initForm()" class="flex-1 overflow-y-auto no-scrollbar bg-gray-50 dark:bg-dark-bg pb-28 relative transition-colors duration-200">

    <!-- Header (Sticky) -->
    <div class="bg-white dark:bg-dark-bg/95 px-5 pt-4 pb-4 shadow-sm border-b border-gray-100 dark:border-dark-border flex items-center sticky top-0 z-30 backdrop-blur-md transition-colors duration-200">
        <button onclick="history.back()" class="p-2 -ml-2 rounded-full hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 active:bg-gray-100 dark:active:bg-gray-700 transition-colors">
            <i data-lucide="arrow-left" class="w-6 h-6"></i>
        </button>
        <h1 class="text-lg font-bold text-gray-900 dark:text-white ml-2">Edit Profile</h1>
    </div>

    <div class="px-5 pt-6 safe-pb">
        <form @submit.prevent="saveProfile" class="space-y-6">

            <!-- Dynamic Message Box -->
            <div x-show="message" x-transition
                 :class="isError ? 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border border-red-100 dark:border-red-900' : 'bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 border border-green-100 dark:border-green-900'"
                 class="p-3 rounded-xl text-sm font-medium text-center"
                 x-text="message" x-cloak>
            </div>

            <!-- Profile Picture -->
            <div class="flex flex-col items-center justify-center mb-6">
                <div class="relative group cursor-pointer active:scale-95 transition-transform" @click="$refs.fileInput.click()">
                    <div class="w-24 h-24 rounded-full p-1 border-2 border-dashed border-app-primary bg-blue-50 dark:bg-blue-900/20">
                        <img :src="form.avatar" class="w-full h-full rounded-full object-cover shadow-sm">
                    </div>
                    <div class="absolute bottom-0 right-0 bg-app-primary text-white p-2 rounded-full shadow-md border-2 border-white dark:border-dark-bg">
                        <i data-lucide="camera" class="w-4 h-4"></i>
                    </div>
                </div>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-2">Tap to change photo</p>
                <input type="file" x-ref="fileInput" name="profile_image" class="hidden" accept="image/*" @change="previewImage">
            </div>

            <!-- Personal Info -->
            <div class="bg-white dark:bg-dark-card p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 space-y-5 transition-colors duration-200">
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2 border-b border-gray-50 dark:border-gray-700 pb-2">Personal Details</h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">First Name</label>
                        <input type="text" x-model="form.first_name" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">Last Name</label>
                        <input type="text" x-model="form.last_name" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">Display Name</label>
                    <input type="text" x-model="form.display_name" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">Email Address</label>
                    <input type="email" x-model="form.email" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                </div>

                 <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">Phone Number</label>
                    <input type="tel" x-model="form.phone" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors" placeholder="08012345678">
                </div>

                <!-- State Select -->
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">State of Residence</label>
                    <div class="relative">
                        <select x-model="form.state" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors">
                            <option value="">Select State</option>
                            <option value="Abia">Abia</option>
                            <option value="Adamawa">Adamawa</option>
                            <option value="Akwa Ibom">Akwa Ibom</option>
                            <option value="Anambra">Anambra</option>
                            <option value="Bauchi">Bauchi</option>
                            <option value="Bayelsa">Bayelsa</option>
                            <option value="Benue">Benue</option>
                            <option value="Borno">Borno</option>
                            <option value="Cross River">Cross River</option>
                            <option value="Delta">Delta</option>
                            <option value="Ebonyi">Ebonyi</option>
                            <option value="Edo">Edo</option>
                            <option value="Ekiti">Ekiti</option>
                            <option value="Enugu">Enugu</option>
                            <option value="FCT">FCT - Abuja</option>
                            <option value="Gombe">Gombe</option>
                            <option value="Imo">Imo</option>
                            <option value="Jigawa">Jigawa</option>
                            <option value="Kaduna">Kaduna</option>
                            <option value="Kano">Kano</option>
                            <option value="Katsina">Katsina</option>
                            <option value="Kebbi">Kebbi</option>
                            <option value="Kogi">Kogi</option>
                            <option value="Kwara">Kwara</option>
                            <option value="Lagos">Lagos</option>
                            <option value="Nasarawa">Nasarawa</option>
                            <option value="Niger">Niger</option>
                            <option value="Ogun">Ogun</option>
                            <option value="Ondo">Ondo</option>
                            <option value="Osun">Osun</option>
                            <option value="Oyo">Oyo</option>
                            <option value="Plateau">Plateau</option>
                            <option value="Rivers">Rivers</option>
                            <option value="Sokoto">Sokoto</option>
                            <option value="Taraba">Taraba</option>
                            <option value="Yobe">Yobe</option>
                            <option value="Zamfara">Zamfara</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 dark:text-gray-400">
                            <i data-lucide="chevron-down" class="w-4 h-4"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Password -->
            <div class="bg-white dark:bg-dark-card p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 space-y-4 transition-colors duration-200">
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2 border-b border-gray-50 dark:border-gray-700 pb-2">Security</h2>
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 uppercase">New Password</label>
                    <input type="password" x-model="form.password" placeholder="Leave empty to keep current" class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-base text-gray-900 dark:text-white font-medium outline-none focus:ring-2 focus:ring-app-primary/20 appearance-none transition-colors placeholder-gray-400 dark:placeholder-gray-600">
                </div>
            </div>

            <!-- Action Button -->
            <button type="submit" :disabled="isSaving" class="w-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-4 rounded-xl font-bold shadow-lg shadow-gray-200 dark:shadow-none flex items-center justify-center gap-2 active:scale-[0.98] transition-all hover:bg-gray-800 dark:hover:bg-gray-100">
                <template x-if="isSaving">
                    <span class="animate-spin"><i data-lucide="loader-2" class="w-5 h-5"></i></span>
                </template>
                <span x-text="isSaving ? 'Saving...' : 'Save Changes'"></span>
                <template x-if="!isSaving">
                    <i data-lucide="check-circle" class="w-5 h-5"></i>
                </template>
            </button>

            <!-- Extra bottom padding for safe scrolling past floating UI -->
            <div class="h-6"></div>
        </form>
    </div>
</div>