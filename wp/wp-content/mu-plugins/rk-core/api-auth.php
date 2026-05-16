<?php
/**
 * Module: Authentication & User Management
 * Handles Registration, Profiles, Security Checks, and Bank Accounts.
 */

// 1. GLOBAL USER REGISTER HOOK (Ensures Bonus is ALWAYS given)
add_action('user_register', 'rk_ensure_welcome_bonus', 10, 1);

function rk_ensure_welcome_bonus($user_id) {
    if (get_user_meta($user_id, 'rk_welcome_bonus_given', true)) {
        return;
    }

    update_user_meta($user_id, 'wallet_balance', 300);
    update_user_meta($user_id, 'earnings_balance', 0);
    update_user_meta($user_id, 'rk_welcome_bonus_given', 1);
    update_user_meta($user_id, 'rk_has_seen_welcome', 0);

    global $wpdb;
    $table_txn = $wpdb->prefix . 'raffle_transactions';
    $txn_ref = 'WELCOME-' . $user_id;
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_txn} WHERE user_id = %d AND txn_ref = %s LIMIT 1", $user_id, $txn_ref));
    if (!$existing) {
        $wpdb->insert($table_txn, [
            'user_id' => $user_id,
            'claimed_amount' => 300,
            'status' => 'verified_final',
            'type' => 'signup_bonus',
            'proof_url' => 'system_welcome',
            'txn_ref' => $txn_ref,
            'created_at' => current_time('mysql')
        ]);
    }
}

// 2. AVATAR FILTER
add_filter('get_avatar_url', function($url, $id_or_email, $args) {
    $user_id = 0;
    if (is_numeric($id_or_email)) $user_id = (int)$id_or_email;
    elseif (is_string($id_or_email) && ($user = get_user_by('email', $id_or_email))) $user_id = $user->ID;
    elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) $user_id = (int)$id_or_email->user_id;

    if ($user_id) {
        $custom_avatar = get_user_meta($user_id, 'profile_pic_url', true);
        if ($custom_avatar) return $custom_avatar;
    }
    return $url;
}, 10, 3);

// Helper Wrapper for Profile
function rk_handle_profile_request($request) {
    if ($request->get_method() === 'POST') return rk_update_user_profile($request);
    return rk_get_user_profile($request);
}

function rk_check_auth($request) {
    // Kept for backward compatibility if other plugins use it
    return is_user_logged_in();
}

function rk_auth_cookie_params() {
    return [
        'logged_in' => is_user_logged_in(),
        'user_id' => get_current_user_id(),
    ];
}

function rk_auth_safe_return_url($fallback = 'index.php') {
    $raw = isset($_GET['return']) ? wp_unslash($_GET['return']) : (isset($_GET['redirect_to']) ? wp_unslash($_GET['redirect_to']) : '');
    if (!$raw) return $fallback;
    $raw = trim((string) $raw);
    if ($raw === '' || strpos($raw, '//') === 0 || preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) return $fallback;
    $path = parse_url($raw, PHP_URL_PATH) ?: '';
    $query = parse_url($raw, PHP_URL_QUERY);
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false || !preg_match('/^[A-Za-z0-9_\-\/\.]+$/', $path)) return $fallback;
    return $path . ($query ? '?' . $query : '');
}

function rk_login_url_with_return($fallback = 'login.php') {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = $request_uri ? parse_url($request_uri, PHP_URL_PATH) : '';
    $query = $request_uri ? parse_url($request_uri, PHP_URL_QUERY) : '';
    $relative = ltrim((string) $path, '/');
    if ($query) $relative .= '?' . $query;
    if ($relative === '' || strpos($relative, '..') !== false) return $fallback;
    return $fallback . '?return=' . rawurlencode($relative);
}

function rk_get_turnstile_secret_key() {
    if (defined('RK_TURNSTILE_SECRET_KEY') && RK_TURNSTILE_SECRET_KEY) return RK_TURNSTILE_SECRET_KEY;
    $env = getenv('RK_TURNSTILE_SECRET_KEY');
    if ($env) return $env;
    return get_option('rk_turnstile_secret_key', '');
}

function rk_verify_turnstile_token($token, $remote_ip = '') {
    $secret = rk_get_turnstile_secret_key();
    if (!$secret) {
        return new WP_Error('turnstile_not_configured', 'Registration security check is not configured. Please contact support.', ['status' => 500]);
    }
    if (!$token) return new WP_Error('turnstile_missing', 'Please complete the security challenge.', ['status' => 400]);

    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 10,
        'body' => array_filter([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $remote_ip,
        ]),
    ]);
    if (is_wp_error($response)) return new WP_Error('turnstile_unavailable', 'Security challenge could not be verified. Please try again.', ['status' => 503]);
    $payload = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($payload['success'])) return new WP_Error('turnstile_failed', 'Security challenge failed. Please retry.', ['status' => 400]);
    return true;
}

function rk_find_referrer_by_code($code) {
    $code = sanitize_text_field($code);
    if ($code === '') return null;
    $user = get_user_by('login', $code) ?: get_user_by('email', $code);
    if ($user) return $user;
    if (ctype_digit($code)) {
        $by_id = get_userdata((int) $code);
        if ($by_id) return $by_id;
    }
    $users = get_users([
        'number' => 1,
        'meta_query' => [[
            'key' => 'rk_referral_code',
            'value' => $code,
            'compare' => '=',
        ]],
        'fields' => 'all',
    ]);
    return $users ? $users[0] : null;
}

function rk_get_profile_completion_state($user_id) {
    $user = get_userdata($user_id);
    $bank_accounts = get_user_meta($user_id, 'rk_bank_accounts', true);
    if (!is_array($bank_accounts)) $bank_accounts = [];
    $missing = [];
    if (!$user || trim($user->first_name . $user->last_name) === '') $missing[] = 'name';
    if (!get_user_meta($user_id, 'phone_number', true)) $missing[] = 'phone';
    if (!get_user_meta($user_id, 'profile_pic_url', true)) $missing[] = 'avatar';
    if (count($bank_accounts) === 0) $missing[] = 'bank_details';
    if (!get_user_meta($user_id, 'rk_onesignal_id', true)) $missing[] = 'notification_permission';
    return [
        'is_complete' => empty(array_diff($missing, ['notification_permission'])),
        'missing' => $missing,
        'bank_accounts_count' => count($bank_accounts),
        'welcome_bonus' => [
            'given' => (bool) get_user_meta($user_id, 'rk_welcome_bonus_given', true),
            'amount' => 300,
            'seen' => (bool) get_user_meta($user_id, 'rk_has_seen_welcome', true),
        ],
    ];
}

/**
 * SECURITY CORE: User Status & Ban Enforcement
 * Connects directly to the Admin Panel "User Manager" settings.
 */
function rk_check_user_status($user_id, $action = 'general') {
    // 1. Check Auto-Expiry (If ban has passed)
    $expiry_date = get_user_meta($user_id, 'rk_ban_expiry', true);
    if (!empty($expiry_date)) {
        $today = date('Y-m-d');
        if ($today > $expiry_date) {
            // Lift all bans automatically
            delete_user_meta($user_id, 'rk_is_banned');
            delete_user_meta($user_id, 'rk_ban_withdraw');
            delete_user_meta($user_id, 'rk_ban_transfer');
            delete_user_meta($user_id, 'rk_ban_expiry');
            return true; // User is free
        }
    }

    // 2. Global Full Ban (Blocks Login, Profile, AND Playing)
    // Matches Admin Panel key: 'rk_is_banned'
    $is_banned = get_user_meta($user_id, 'rk_is_banned', true);
    if ($is_banned) {
        return new WP_Error('user_banned', '🚫 Account Suspended. Contact support.', ['status' => 403]);
    }

    // 3. Action-Specific Restrictions
    if ($action === 'withdraw') {
        $ban_withdraw = get_user_meta($user_id, 'rk_ban_withdraw', true);
        if ($ban_withdraw) {
            return new WP_Error('action_banned', '🚫 Withdrawals are currently disabled for your account.', ['status' => 403]);
        }
    }

    if ($action === 'transfer') {
        $ban_transfer = get_user_meta($user_id, 'rk_ban_transfer', true);
        if ($ban_transfer) {
            return new WP_Error('action_banned', '🚫 Transfers are disabled for your account.', ['status' => 403]);
        }
    }

    // 4. Spin/Play Restriction
    // If global ban is NOT set, but we are checking for 'play' or 'spin'
    // Currently, we assume Global Ban covers this.

    return true;
}

function rk_handle_new_registration($request) {
    $limit_check = rk_check_rate_limit('register', 3, 300);
    if (is_wp_error($limit_check)) return $limit_check;

    $params = $request->get_body_params();
    $files = $request->get_file_params();
    $username = sanitize_user($params['username'] ?? '');
    $email = sanitize_email($params['email'] ?? '');
    $password = (string) ($params['password'] ?? '');
    $state = isset($params['state']) ? sanitize_text_field($params['state']) : '';
    $turnstile_token = sanitize_text_field($params['cf-turnstile-response'] ?? $params['turnstile_token'] ?? '');

    $turnstile = rk_verify_turnstile_token($turnstile_token, $_SERVER['REMOTE_ADDR'] ?? '');
    if (is_wp_error($turnstile)) return $turnstile;

    if (empty($username) || empty($email) || empty($password)) return new WP_Error('missing', 'Username, email, and password are required', ['status' => 400]);
    if (!is_email($email)) return new WP_Error('invalid_email', 'Enter a valid email address', ['status' => 400]);
    if (strlen($password) < 6) return new WP_Error('weak_password', 'Password must be at least 6 characters', ['status' => 400]);
    if (username_exists($username)) return new WP_Error('exists', 'Username taken', ['status' => 400]);
    if (email_exists($email)) return new WP_Error('exists', 'Email taken', ['status' => 400]);

    $referrer_code = sanitize_text_field($params['referrer'] ?? $params['ref'] ?? $params['referral_code'] ?? '');
    $referrer = null;
    if ($referrer_code !== '') {
        $referrer = rk_find_referrer_by_code($referrer_code);
        if (!$referrer) return new WP_Error('invalid_referral', 'Referral code was not found. Please check the link or remove the code.', ['status' => 400]);
    }

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) return new WP_Error('create_failed', $user_id->get_error_message(), ['status' => 500]);

    if (!empty($state)) update_user_meta($user_id, 'state_of_residence', $state);
    update_user_meta($user_id, 'rk_referral_code', $username);

    if ($referrer && (int) $referrer->ID !== (int) $user_id) {
        update_user_meta($user_id, 'referred_by', $referrer->ID);
        update_user_meta($user_id, 'rk_referrer_code_used', $referrer_code);
        $count = (int) get_user_meta($referrer->ID, 'rk_referral_count', true);
        update_user_meta($referrer->ID, 'rk_referral_count', $count + 1);
        $current_pts = (int) get_user_meta($referrer->ID, 'rk_points', true);
        update_user_meta($referrer->ID, 'rk_points', $current_pts + 50);
    }

    $avatar_url = '';
    if (!empty($files['profile_image'])) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $attachment_id = media_handle_sideload($files['profile_image'], 0);
        if (!is_wp_error($attachment_id)) {
            wp_update_post(['ID' => $attachment_id, 'post_author' => $user_id]);
            $avatar_url = wp_get_attachment_url($attachment_id);
            update_user_meta($user_id, 'simple_local_avatar', ['full' => $avatar_url, 'media_id' => $attachment_id]);
            update_user_meta($user_id, 'wp_user_avatar', $attachment_id);
            update_user_meta($user_id, 'profile_pic_url', $avatar_url);
        }
    }
    if (!$avatar_url) $avatar_url = get_avatar_url($user_id);
    clean_user_cache($user_id);

    return [
        'success' => true,
        'user_id' => $user_id,
        'message' => 'Account created',
        'avatar_url' => $avatar_url,
        'welcome_bonus' => ['given' => true, 'amount' => 300, 'seen' => false],
        'referral' => ['code' => $referrer_code, 'accepted' => (bool) $referrer],
        'profile_completion' => rk_get_profile_completion_state($user_id),
    ];
}

function rk_get_user_profile($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    // ENFORCE BAN CHECK ON PROFILE LOAD
    if (function_exists('rk_check_user_status')) {
        $status = rk_check_user_status($user_id, 'general');
        if (is_wp_error($status)) return $status;
    }

    $u = get_userdata($user_id);
    $avatar = get_user_meta($user_id, 'profile_pic_url', true) ?: get_avatar_url($user_id);

    // *** UPDATED: Return the seen status ***
    // (bool) casting ensures it returns false if meta doesn't exist
    $has_seen = (bool) get_user_meta($user_id, 'rk_has_seen_welcome', true);

    return [
        'id' => $user_id,
        'first_name' => $u->first_name,
        'last_name' => $u->last_name,
        'display_name' => $u->display_name,
        'email' => $u->user_email,
        'phone' => get_user_meta($user_id, 'phone_number', true),
        'state' => get_user_meta($user_id, 'state_of_residence', true),
        'avatar' => $avatar,
        'has_seen_welcome' => $has_seen,
        'welcome_bonus' => [
            'given' => (bool) get_user_meta($user_id, 'rk_welcome_bonus_given', true),
            'amount' => 300,
            'seen' => $has_seen,
        ],
        'bans' => [
            'is_banned' => (bool) get_user_meta($user_id, 'rk_is_banned', true),
            'withdrawal_banned' => (bool) get_user_meta($user_id, 'rk_ban_withdraw', true),
            'transfer_banned' => (bool) get_user_meta($user_id, 'rk_ban_transfer', true),
            'expiry' => get_user_meta($user_id, 'rk_ban_expiry', true),
        ],
        'profile_completion' => rk_get_profile_completion_state($user_id)
    ];
}

function rk_update_user_profile($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    // BAN CHECK
    if (is_wp_error($status = rk_check_user_status($user_id, 'general'))) return $status;

    $params = $request->get_body_params();
    $files = $request->get_file_params();
    $user_data = ['ID' => $user_id];
    if(!empty($params['first_name'])) $user_data['first_name'] = sanitize_text_field($params['first_name']);
    if(!empty($params['last_name'])) $user_data['last_name'] = sanitize_text_field($params['last_name']);
    if(!empty($params['display_name'])) $user_data['display_name'] = sanitize_text_field($params['display_name']);
    if(!empty($params['email'])) $user_data['user_email'] = sanitize_email($params['email']);
    if(!empty($params['password'])) $user_data['user_pass'] = $params['password'];
    if (count($user_data) > 1) wp_update_user($user_data);
    if(isset($params['state'])) update_user_meta($user_id, 'state_of_residence', sanitize_text_field($params['state']));
    if(isset($params['phone'])) update_user_meta($user_id, 'phone_number', sanitize_text_field($params['phone']));

    $avatar_url = '';
    if (!empty($files['profile_image'])) {
        require_once(ABSPATH . 'wp-admin/includes/image.php'); require_once(ABSPATH . 'wp-admin/includes/file.php'); require_once(ABSPATH . 'wp-admin/includes/media.php');
        $attachment_id = media_handle_sideload($files['profile_image'], 0);
        if (!is_wp_error($attachment_id)) {
            wp_update_post(['ID' => $attachment_id, 'post_author' => $user_id]);
            $avatar_url = wp_get_attachment_url($attachment_id);
            update_user_meta($user_id, 'simple_local_avatar', ['full' => $avatar_url, 'media_id' => $attachment_id]);
            update_user_meta($user_id, 'wp_user_avatar', $attachment_id);
            update_user_meta($user_id, 'profile_pic_url', $avatar_url);
        }
    }
    if (!$avatar_url) $avatar_url = get_user_meta($user_id, 'profile_pic_url', true);
    if (!$avatar_url) $avatar_url = get_avatar_url($user_id);
    return ['success' => true, 'avatar' => $avatar_url, 'message' => 'Profile Updated'];
}

function rk_handle_verify_reset_code($request) {
    $email = sanitize_email($request->get_param('email'));
    $otp = sanitize_text_field($request->get_param('otp'));
    if (empty($email) || empty($otp)) return new WP_Error('missing_fields', 'Email and reset code are required', ['status' => 400]);
    $user = get_user_by('email', $email);
    if (!$user) return new WP_Error('not_found', 'User not found', ['status' => 404]);
    $stored_otp = get_user_meta($user->ID, 'rk_reset_otp', true);
    $stored_expiry = (int) get_user_meta($user->ID, 'rk_reset_expiry', true);
    if (!$stored_otp || $stored_otp != $otp) return new WP_Error('invalid_code', 'Invalid reset code', ['status' => 400]);
    if (time() > $stored_expiry) return new WP_Error('expired_code', 'Reset code has expired. Please request a new code.', ['status' => 400]);
    return ['success' => true, 'state' => 'verify-code', 'message' => 'Code verified. You can set a new password.'];
}

function rk_handle_reset_password($request) {
    $mode = sanitize_key($request->get_param('mode') ?: 'set-new-password');
    if ($mode === 'verify-code') return rk_handle_verify_reset_code($request);
    if ($mode === 'resend' || $mode === 'request-code') return rk_handle_forgot_password($request);

    $email = sanitize_email($request->get_param('email'));
    $otp = sanitize_text_field($request->get_param('otp'));
    $password = (string) $request->get_param('password');

    if (empty($email) || empty($otp) || empty($password)) {
        return new WP_Error('missing_fields', 'All fields are required', ['status' => 400, 'state' => 'set-new-password']);
    }
    if (strlen($password) < 6) {
        return new WP_Error('weak_password', 'Password must be at least 6 characters', ['status' => 400, 'state' => 'set-new-password']);
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return new WP_Error('weak_password', 'Password must contain both letters and numbers', ['status' => 400, 'state' => 'set-new-password']);
    }

    $verify = rk_handle_verify_reset_code($request);
    if (is_wp_error($verify)) return $verify;
    $user = get_user_by('email', $email);
    wp_set_password($password, $user->ID);
    delete_user_meta($user->ID, 'rk_reset_otp');
    delete_user_meta($user->ID, 'rk_reset_expiry');

    return ['success' => true, 'state' => 'success', 'message' => 'Password changed successfully. You can now login.'];
}

function rk_get_bank_accounts($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    return get_user_meta($user_id, 'rk_bank_accounts', true) ?: [];
}

function rk_save_bank_account($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    // BAN CHECK
    if (is_wp_error($status = rk_check_user_status($user_id, 'general'))) return $status;

    // Use get_param() to correctly intercept variables from our internal Local Proxy
    $bank_name      = sanitize_text_field($request->get_param('bank_name'));
    $account_number = sanitize_text_field($request->get_param('account_number'));
    $account_name   = sanitize_text_field($request->get_param('account_name'));

    if (empty($bank_name) || empty($account_number) || empty($account_name)) {
        return new WP_Error('missing_fields', 'Bank name, 10-digit account number, and account name are required', ['status' => 400]);
    }
    if (strlen($bank_name) < 2) return new WP_Error('invalid_bank_name', 'Enter a valid Nigerian bank name', ['status' => 400]);
    if (!preg_match('/^[0-9]{10}$/', $account_number)) return new WP_Error('invalid_account_number', 'Nigerian account numbers must be exactly 10 digits', ['status' => 400]);
    if (!preg_match('/^[A-Za-z .\'-]{3,80}$/', $account_name)) return new WP_Error('invalid_account_name', 'Enter the account name as it appears at the bank', ['status' => 400]);

    $accounts = get_user_meta($user_id, 'rk_bank_accounts', true) ?: [];
    if (count($accounts) >= 2) return new WP_Error('limit_reached', 'Max 2 accounts allowed', ['status' => 400]);

    $new_account = [
        'id'             => uniqid(),
        'bank_name'      => $bank_name,
        'account_number' => $account_number,
        'account_name'   => $account_name,
        'is_primary'     => count($accounts) === 0
    ];
    $accounts[] = $new_account;

    update_user_meta($user_id, 'rk_bank_accounts', $accounts);
    return ['success' => true, 'accounts' => $accounts];
}

function rk_delete_bank_account($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    // BAN CHECK
    if (is_wp_error($status = rk_check_user_status($user_id, 'general'))) return $status;

    $id = sanitize_text_field($request->get_param('id'));
    if (!$id) return new WP_Error('missing_id', 'Account ID is required', ['status' => 400]);
    $accounts = get_user_meta($user_id, 'rk_bank_accounts', true) ?: [];
    $new_accounts = array_values(array_filter($accounts, function($acc) use ($id) { return $acc['id'] !== $id; }));
    if (!empty($new_accounts) && empty(array_filter($new_accounts, fn($a) => $a['is_primary']))) $new_accounts[0]['is_primary'] = true;
    update_user_meta($user_id, 'rk_bank_accounts', $new_accounts);
    return ['success' => true, 'accounts' => $new_accounts];
}

function rk_save_push_device($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    // BAN CHECK
    if (is_wp_error($status = rk_check_user_status($user_id))) return $status;

    // Use get_param() to future-proof for Local Proxy architecture
    $player_id = sanitize_text_field($request->get_param('player_id'));

    if(empty($player_id)) return new WP_Error('missing_id', 'Device ID Missing', ['status' => 400]);
    $existing = get_user_meta($user_id, 'rk_onesignal_id', true);
    if($existing !== $player_id) update_user_meta($user_id, 'rk_onesignal_id', $player_id);
    return ['success' => true, 'message' => 'Device Registered'];
}

// *** NEW FUNCTION: Mark Welcome as Seen ***
function rk_acknowledge_welcome_bonus($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    update_user_meta($user_id, 'rk_has_seen_welcome', 1);
    return ['success' => true];
}

/**
 * PRIORITY 1: WELCOME EMAIL (Highest Value - Drives Engagement)
 * Only send ONCE per user on registration
 */
function rk_send_welcome_email($user_id) {
    // Check if already sent (prevent duplicates)
    if (get_user_meta($user_id, 'rk_welcome_email_sent', true)) {
        return;
    }

    $user = get_userdata($user_id);
    if (!$user) return;

    $email = $user->user_email;
    $name = $user->display_name ?: $user->user_login;

    $subject = "🎉 Welcome to RaffleKings - ₦300 Bonus Inside!";

    $body = "
        <p style='font-size:17px;'>Hi <strong>$name</strong>,</p>
        <p>Welcome to <strong>RaffleKings</strong> - Nigeria's most trusted raffle platform! 🎊</p>

        <div style='background:linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-left:4px solid #16a34a; padding:20px; margin:24px 0; border-radius:8px;'>
            <p style='margin:0 0 8px; font-size:20px; font-weight:bold; color:#16a34a;'>
                ✅ ₦300 Welcome Bonus Activated!
            </p>
            <p style='margin:0; font-size:15px; color:#166534;'>
                Your free bonus is ready. Use it to buy your first ticket and start winning today.
            </p>
        </div>

        <p style='margin-top:24px;'><strong>Quick Start Guide:</strong></p>
        <table style='width:100%; border-spacing:0;'>
            <tr>
                <td style='padding:12px; background:#f8f9fa; border-radius:8px; margin-bottom:8px;'>
                    <strong style='color:#007AFF;'>1.</strong> Browse active raffles
                </td>
            </tr>
            <tr><td style='height:8px;'></td></tr>
            <tr>
                <td style='padding:12px; background:#f8f9fa; border-radius:8px; margin-bottom:8px;'>
                    <strong style='color:#007AFF;'>2.</strong> Pick your lucky numbers
                </td>
            </tr>
            <tr><td style='height:8px;'></td></tr>
            <tr>
                <td style='padding:12px; background:#f8f9fa; border-radius:8px;'>
                    <strong style='color:#007AFF;'>3.</strong> Wait for the draw & WIN! 💰
                </td>
            </tr>
        </table>

        <p style='margin-top:28px; font-size:14px; color:#6c757d;'>
            Need help? Contact us at
            <a href='mailto:help@rafflekings.com.ng' style='color:#007AFF; text-decoration:none;'>help@rafflekings.com.ng</a>
        </p>
    ";

    if (function_exists('rk_get_email_html')) {
        $message = rk_get_email_html("Welcome to RaffleKings!", $body, "Start Playing Now →", "https://rafflekings.com.ng/raffles");
    } else {
        $message = $body; // Fallback
    }

    if (function_exists('rk_send_email')) {
        $sent = rk_send_email($email, $subject, $message);
    } else {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($email, $subject, $message, $headers);
    }

    if ($sent) {
        update_user_meta($user_id, 'rk_welcome_email_sent', 1);
    }
}

// Hook into user registration (priority 20 to run after balance setup)
add_action('user_register', 'rk_send_welcome_email', 20, 1);

/**
 * UPDATED: Password Reset with Branded Email Template
 */
function rk_handle_forgot_password($request) {
    $email = sanitize_email($request->get_param('email'));
    if (!is_email($email)) return new WP_Error('invalid_email', 'Invalid email address', ['status' => 400]);

    $user = get_user_by('email', $email);
    if (!$user) return new WP_Error('not_found', 'User not found', ['status' => 404]);

    // Generate 6-digit OTP
    $otp = rand(100000, 999999);
    $expiry = time() + (15 * 60); // 15 mins

    update_user_meta($user->ID, 'rk_reset_otp', $otp);
    update_user_meta($user->ID, 'rk_reset_expiry', $expiry);

    // ✅ UPDATED: Use branded email template
    $subject = "🔐 Your Password Reset Code";

    $body = "
        <p style='font-size:17px;'>Hi <strong>{$user->display_name}</strong>,</p>
        <p>We received a request to reset your RaffleKings password. Use the code below:</p>

        <div style='background:linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border:2px solid #007AFF; padding:28px; margin:24px 0; border-radius:12px; text-align:center;'>
            <p style='margin:0 0 8px; font-size:13px; color:#0051d5; text-transform:uppercase; letter-spacing:1.5px; font-weight:600;'>Your Reset Code</p>
            <p style='margin:0; font-size:36px; font-weight:bold; color:#007AFF; letter-spacing:4px; font-family:monospace;'>
                $otp
            </p>
        </div>

        <div style='background:#fef3c7; border-left:4px solid #f59e0b; padding:16px; margin:20px 0; border-radius:6px;'>
            <p style='margin:0; font-size:14px; color:#92400e;'>
                ⏰ <strong>Expires in 15 minutes</strong> - Enter this code quickly!
            </p>
        </div>

        <p style='margin-top:24px; font-size:14px; color:#6c757d;'>
            If you didn't request this, ignore this email. Your account is safe.
        </p>
    ";

    // ✅ Use the master email template wrapper
    if (function_exists('rk_get_email_html')) {
        $message = rk_get_email_html("Reset Your Password", $body, "Reset Password Now →", "https://rafflekings.com.ng/reset-password");
    } else {
        $message = $body;
    }

    // ✅ Send via throttled email system
    if (function_exists('rk_send_email')) {
        rk_send_email($email, $subject, $message);
    } else {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($email, $subject, $message, $headers);
    }

    return ['success' => true, 'message' => 'Reset code sent to your email (check spam folder).'];
}

// ==========================================
// TRIGGER 3: NEW USER REGISTRATION NOTIFICATION
// ==========================================

/**
 * Send Telegram alert when a new user registers
 */
add_action('user_register', 'rk_notify_telegram_new_user', 30, 1);

function rk_notify_telegram_new_user($user_id) {
    if (!function_exists('rk_send_telegram_alert')) return;

    $user = get_userdata($user_id);
    if (!$user) return;

    $referrer_id = get_user_meta($user_id, 'referred_by', true);
    $referrer_text = '';

    if ($referrer_id) {
        $referrer = get_userdata($referrer_id);
        if ($referrer) {
            $referrer_text = "\n👥 Referred by: <b>" . $referrer->display_name . "</b>";
        }
    }

    $message = "🎉 <b>NEW USER REGISTERED</b>\n\n" .
               "👤 Name: <b>" . $user->display_name . "</b>\n" .
               "📧 Email: <code>" . $user->user_email . "</code>\n" .
               "🆔 User ID: <code>#$user_id</code>" .
               $referrer_text . "\n\n" .
               "⏰ " . current_time('F j, Y - g:i A');

    rk_send_telegram_alert($message);
}
?>