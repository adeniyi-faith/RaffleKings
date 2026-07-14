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

<?php require_once "components/user/edit-profile-js.php"; ?>

<link rel="stylesheet" href="assets/css/user/edit-profile.css">

<!-- Main Container -->
<div x-data="editProfile()" x-init="initForm()" class="flex-1 overflow-y-auto no-scrollbar bg-gray-50 dark:bg-dark-bg pb-28 relative transition-colors duration-200">

    <!-- Header (Sticky) -->
    <div class="bg-white dark:bg-dark-bg/95 px-5 pt-4 pb-4 shadow-sm border-b border-gray-100 dark:border-dark-border flex items-center sticky top-0 z-30 backdrop-blur-md transition-colors duration-200">
        <button onclick="history.back()" aria-label="Go back" class="p-2 -ml-2 rounded-full hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 active:bg-gray-100 dark:active:bg-gray-700 transition-colors">
            <i data-lucide="arrow-left" class="w-6 h-6"></i>
        </button>
        <h1 class="text-lg font-bold text-gray-900 dark:text-white ml-2">Edit Profile</h1>
    </div>

    <div class="px-5 pt-6 safe-pb">
        <?php require_once "components/user/edit-profile-form.php"; ?>
    </div>
</div>