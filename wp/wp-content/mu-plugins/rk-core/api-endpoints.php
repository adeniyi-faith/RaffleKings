<?php
/**
 * Module: REST API Endpoints and Core Business Logic - Loader
 * * Refactored Architecture:
 * 1. api-system.php        (Core Utils, Logs, Notifications)
 * 2. api-auth.php          (User Registration, Profile, Security)
 * 3. api-financials.php    (Wallet, Payments, Withdrawals)
 * 4. api-gamification.php (Raffles, Spins, Chat)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// TURNSTILE SITE KEY ONLY. Secret keys must be provided by wp-config.php,
// the RK_TURNSTILE_SECRET_KEY environment variable, or the rk_turnstile_secret_key option.
if (!defined('RK_TURNSTILE_SITE_KEY')) define('RK_TURNSTILE_SITE_KEY', '0x4AAAAAACMsPBMFl2oCJQvS');

// *** CRITICAL FIX FOR HEADERLESS SITES: CORS HANDLER ***
add_action('init', function() {
    // Allow multiple origins dynamically
    $allowed_origins = ['https://rafflekings.com.ng', 'https://www.rafflekings.com.ng', 'https://getonlinestudio.com', 'http://localhost:3000', 'https://cdn.tailwindcss.com'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }

    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
        status_header(200); exit();
    }
});

// LOAD MODULES
require_once __DIR__ . '/api-system.php';
require_once __DIR__ . '/api-auth.php';
require_once __DIR__ . '/api-financials.php';
require_once __DIR__ . '/api-gamification.php';

// 3. API INIT (Router)
add_action('rest_api_init', function () {
    $ns = 'raffle/v1'; // Namespace shortcut

    // --- PUBLIC ROUTES (No Auth Required) ---

    // Hall of Fame
    register_rest_route('raffle/v1', '/hall-of-fame', [
        'methods' => 'GET',
        'callback' => 'rk_get_hall_of_fame',
        'permission_callback' => '__return_true'
    ]);

    // Site Settings (Bank Info & Keys)
    register_rest_route('raffle/v1', '/settings', [
        'methods' => 'GET',
        'callback' => function(){
            return [
                'bank_name' => get_option('rk_bank_name'),
                'account_number' => get_option('rk_account_number'),
                'account_name' => get_option('rk_account_name'),
                'turnstile_site_key' => defined('RK_TURNSTILE_SITE_KEY') ? RK_TURNSTILE_SITE_KEY : ''
            ];
        },
        'permission_callback' => '__return_true'
    ]);

    // Registration (Public)
    register_rest_route('lottery/v1', '/register', [
        'methods' => 'POST',
        'callback' => 'rk_handle_new_registration',
        'permission_callback' => '__return_true'
    ]);

    // Password Reset Routes
    register_rest_route('raffle/v1', '/auth/forgot-password', [
        'methods' => 'POST',
        'callback' => 'rk_handle_forgot_password',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('raffle/v1', '/auth/reset-password', [
        'methods' => 'POST',
        'callback' => 'rk_handle_reset_password',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('raffle/v1', '/auth/verify-reset-code', [
        'methods' => 'POST',
        'callback' => 'rk_handle_verify_reset_code',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('raffle/v1', '/auth/resend-reset-code', [
        'methods' => 'POST',
        'callback' => 'rk_handle_forgot_password',
        'permission_callback' => '__return_true'
    ]);

    // Tutorials (Public)
    register_rest_route('raffle/v1', '/tutorials', [
        'methods' => 'GET',
        'callback' => 'rk_get_tutorials',
        'permission_callback' => '__return_true'
    ]);

    // Tutorial Voting (Public/Low Risk)
    register_rest_route('raffle/v1', '/tutorials/helpful', [
        'methods' => 'POST',
        'callback' => 'rk_tutorial_mark_helpful',
        'permission_callback' => '__return_true'
    ]);

    // Site Notices (Public)
    register_rest_route('raffle/v1', '/site-notices', [
        'methods' => 'GET',
        'callback' => 'rk_get_site_notices',
        'permission_callback' => '__return_true'
    ]);

    // Draw Results (Public)
    register_rest_route('raffle/v1', '/draw/results', [
        'methods' => 'GET',
        'callback' => 'rk_get_draw_results',
        'permission_callback' => '__return_true'
    ]);

    // Live Comments (Read Publicly)
    register_rest_route('raffle/v1', '/live/comments', [
        'methods' => 'GET',
        'callback' => 'rk_get_live_comments',
        'permission_callback' => '__return_true'
    ]);

    // System Logs (Open for client-side error reporting)
    register_rest_route('raffle/v1', '/system/log', [
        'methods' => ['POST', 'OPTIONS'],
        'callback' => 'rk_handle_system_log',
        'permission_callback' => function(){ return true; }
    ]);


    // --- SECURED ROUTES (Login Required) ---

    // Balance
    register_rest_route('raffle/v1', '/balance', [
        'methods' => 'GET',
        'callback' => 'rk_get_balance',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Profile Management
    register_rest_route('raffle/v1', '/profile', [
        'methods' => ['GET','POST'],
        'callback' => 'rk_handle_profile_request',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Bank Accounts
    register_rest_route('raffle/v1', '/bank-accounts', [
        'methods' => 'GET',
        'callback' => 'rk_get_bank_accounts',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/bank-accounts', [
        'methods' => 'POST',
        'callback' => 'rk_save_bank_account',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/bank-accounts', [
        'methods' => 'DELETE',
        'callback' => 'rk_delete_bank_account',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Push Device Registration
    register_rest_route('raffle/v1', '/save-device', [
        'methods' => 'POST',
        'callback' => 'rk_save_push_device',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Financials: Payment, Transfer, Withdraw
    register_rest_route('raffle/v1', '/payment', [
        'methods' => 'POST',
        'callback' => 'rk_handle_payment_ai',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/transfer', [
        'methods' => 'POST',
        'callback' => 'rk_handle_transfer',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/withdraw', [
        'methods' => 'POST',
        'callback' => 'rk_handle_withdrawal',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/transactions', [
        'methods' => 'GET',
        'callback' => 'rk_get_user_transactions',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Cart & Tickets
    register_rest_route('raffle/v1', '/cart/sync', [
        'methods' => 'POST',
        'callback' => 'rk_handle_cart_sync',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // *** NEW: APPLY DISCOUNT ENDPOINT ***
    register_rest_route('raffle/v1', '/cart/apply-discount', [
        'methods' => 'POST',
        'callback' => 'rk_apply_recovery_discount',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    register_rest_route('raffle/v1', '/tickets', [
        'methods' => 'GET',
        'callback' => 'rk_get_user_tickets',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Rewards & Gamification
    register_rest_route('raffle/v1', '/claim-daily', [
        'methods' => 'POST',
        'callback' => 'rk_handle_daily_claim',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/rewards-state', [
        'methods' => 'GET',
        'callback' => 'rk_get_rewards_state',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/claim-task', [
        'methods' => 'POST',
        'callback' => 'rk_handle_task_claim',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/referral-stats', [
        'methods' => 'GET',
        'callback' => 'rk_get_referral_stats',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/ack-welcome', [
        'methods' => 'POST',
        'callback' => 'rk_acknowledge_welcome_bonus',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Live Chat (Posting)
    register_rest_route('raffle/v1', '/live/comment', [
        'methods' => 'POST',
        'callback' => 'rk_post_live_comment',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // Spin & Win
    register_rest_route('raffle/v1', '/spin-wheel', [
        'methods' => 'POST',
        'callback' => 'rk_execute_spin_logic',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);
    register_rest_route('raffle/v1', '/redeem-points', [
        'methods' => 'POST',
        'callback' => 'rk_handle_redeem_points',
        'permission_callback' => function() { return is_user_logged_in(); }
    ]);

    // --- ADMIN ROUTES (Manage Options Required) ---

    register_rest_route('raffle/v1', '/draw/run', [
        'methods' => 'POST',
        'callback' => 'rk_run_raffle_draw',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ]);
    register_rest_route('raffle/v1', '/admin/credit-winner', [
        'methods' => 'POST',
        'callback' => 'rk_credit_raffle_winner',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ]);
    register_rest_route('raffle/v1', '/admin/toggle-winner', [
        'methods' => 'POST',
        'callback' => 'rk_toggle_winner_visibility',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ]);
    register_rest_route('raffle/v1', '/admin/revoke-transaction', [
        'methods' => 'POST',
        'callback' => 'rk_revoke_transaction',
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ]);

    // ADMIN DIGEST TRIGGER (Manual)
    register_rest_route('raffle/v1', '/admin/trigger-digest', [
        'methods' => 'GET',
        'callback' => function() {
            if (function_exists('rk_send_admin_template_digest')) {
                rk_send_admin_template_digest();
                return new WP_REST_Response(['success' => true, 'message' => 'Digest sent to admin email.'], 200);
            }
            return new WP_REST_Response(['success' => false, 'message' => 'Function missing'], 500);
        },
        'permission_callback' => function($request) {
            $secret = $request->get_param('secret');
            if ($secret === 'rk_admin_digest') return true;
            return current_user_can('manage_options');
        }
    ]);

    // *** NEW: MANUAL EMAIL TESTER (Verify User Delivery) ***
    // Usage: /wp-json/raffle/v1/debug/test-email?email=test@example.com
    register_rest_route('raffle/v1', '/debug/test-email', [
        'methods' => ['GET', 'POST'],
        'callback' => function($request) {
            $email = $request->get_param('email');

            if (!is_email($email)) {
                return new WP_REST_Response(['success' => false, 'message' => 'Invalid email address provided.'], 400);
            }

            // Check sending method
            $method = defined('FLUENTMAIL_SENDINBLUE_API_KEY') ? 'Brevo API (Direct)' : 'WP Mail (Standard)';

            $subject = "🧪 Test from RaffleKings: " . date('H:i:s');
            $body = "
                <h2>It Works! 🚀</h2>
                <p>This is a manual test email sent from your system.</p>
                <p><strong>Method:</strong> $method</p>
                <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
            ";
            $msg = rk_get_email_html("System Test", $body);

            // Force send
            if (function_exists('rk_send_email')) {
                $sent = rk_send_email($email, $subject, $msg);
            } else {
                $headers = array('Content-Type: text/html; charset=UTF-8');
                $sent = wp_mail($email, $subject, $msg, $headers);
            }

            return [
                'success' => $sent,
                'message' => $sent ? "Email sent to $email via $method" : "Failed to send email. Check error logs.",
                'method_used' => $method
            ];
        },
        // Secure: Only Admins can trigger this
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ]);

    // *** NEW: TELEGRAM TEST ENDPOINT ***
    register_rest_route('raffle/v1', '/test-telegram', [
        'methods' => 'GET',
        'callback' => function() {
            $test_message = "🧪 <b>TEST NOTIFICATION</b>\n\n" .
                           "✅ Telegram integration is <b>WORKING</b>!\n" .
                           "⏰ Time: " . date('Y-m-d H:i:s') . "\n" .
                           "🌍 Server: " . $_SERVER['SERVER_NAME'] . "\n" .
                           "👤 Tested by: " . wp_get_current_user()->user_login;

            // NOTE: rk_send_telegram_alert is defined in api-system.php
            $result = rk_send_telegram_alert($test_message);

            return [
                'success' => $result,
                'message' => $result ? 'Message sent! Check your Telegram.' : 'Failed. Check error logs at /wp-content/debug.log',
                'timestamp' => current_time('mysql'),
                'bot_token_set' => defined('RK_TELEGRAM_BOT_TOKEN') && !empty(RK_TELEGRAM_BOT_TOKEN),
                'chat_ids_set' => defined('RK_TELEGRAM_ADMIN_IDS') && !empty(RK_TELEGRAM_ADMIN_IDS)
            ];
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // ==========================================
    // 🛠️ DEBUG TOOL: TEST PUSH NOTIFICATIONS
    // ==========================================
    register_rest_route($ns, '/debug/test-push', [
        'methods' => 'POST',
        'callback' => 'rk_manual_push_test',
        'permission_callback' => function() {
            // MODIFIED: Allow ANY logged-in user to test their own notifications
            return is_user_logged_in();
        }
    ]);

    // Register Fields
    register_rest_field('raffle', 'raffle_meta', ['get_callback' => 'rk_get_raffle_meta']);
    register_rest_field('raffle', 'image_url', ['get_callback' => function($post) { return has_post_thumbnail($post['id']) ? get_the_post_thumbnail_url($post['id'], 'medium_large') : ''; }]);
});

/**
 * LOGIC: Apply Discount Code
 * Added here to ensure availability if not present in api-financials.php
 */
if (!function_exists('rk_apply_recovery_discount')) {
    function rk_apply_recovery_discount($request) {
        $user_id = get_current_user_id();
        $params = $request->get_json_params();
        $code = isset($params['code']) ? strtoupper(sanitize_text_field($params['code'])) : '';

        if (empty($code)) {
            return new WP_REST_Response(['success' => false, 'message' => 'No coupon code provided.'], 400);
        }

        // Configuration: Define your valid codes here
        $valid_codes = [
            'GOLDENBOX' => ['type' => 'percent', 'amount' => 10, 'desc' => '10% Golden Box Discount'],
            'WELCOME'   => ['type' => 'fixed', 'amount' => 500, 'desc' => '₦500 Welcome Bonus'],
            'VIP20'     => ['type' => 'percent', 'amount' => 20, 'desc' => 'VIP 20% Off']
        ];

        if (!array_key_exists($code, $valid_codes)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid or expired coupon code.'], 404);
        }

        // Save the discount to user meta.
        // NOTE: Your 'rk_handle_cart_sync' function should check this meta key to apply the math.
        update_user_meta($user_id, 'rk_active_cart_discount', [
            'code' => $code,
            'details' => $valid_codes[$code],
            'timestamp' => time()
        ]);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Discount applied: ' . $valid_codes[$code]['desc'],
            'discount' => $valid_codes[$code]
        ], 200);
    }
}

/**
 * MANUAL PUSH TEST CALLBACK
 */
function rk_manual_push_test($request) {
    $user_id = get_current_user_id();
    $params = $request->get_json_params();
    $type = isset($params['type']) ? $params['type'] : 'random';

    // 1. Get OneSignal ID
    $player_id = get_user_meta($user_id, 'rk_onesignal_id', true);
    if (!$player_id) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Your account is not subscribed to notifications yet. Go to Rewards page first.'
        ], 400);
    }

    // 2. Generate Message
    $title = "";
    $body = "";

    if ($type === 'streak') {
        $title = "🔥 TEST: Streak Danger!";
        $body = "This is how the streak warning looks.";
    } elseif ($type === 'winner') {
        $title = "⚡ TEST: Winner Alert";
        $body = "Someone just won ₦50,000!";
    } else {
        // Test the Dynamic Engine
        $user_data = get_userdata($user_id);
        $name = $user_data->display_name;

        // This function is in cron-system.php, verify it's loaded
        if (function_exists('rk_get_random_clickbait_template')) {
            $template = rk_get_random_clickbait_template($user_data); // UPDATED: Pass User Object

            if ($template) {
                // Map 'subject' to 'title' and strip HTML from body
                $title = "[TEST] " . $template['subject'];
                $plain_body = strip_tags($template['body']);
                $plain_body = str_replace(["&nbsp;", "\n\n"], [" ", "\n"], $plain_body);
                $body = substr(trim($plain_body), 0, 150);
            } else {
                $title = "Test Push";
                $body = "No active template criteria met (try different time of day?)";
            }
        } else {
            $title = "Test Push";
            $body = "Dynamic engine function not loaded.";
        }
    }

    // 3. Send Immediately
    if (function_exists('rk_send_push_notification_direct')) {
        rk_send_push_notification_direct($player_id, $title, $body);
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Notification Sent!',
            'content' => ['title' => $title, 'body' => $body]
        ], 200);
    }

    return new WP_REST_Response(['success' => false, 'message' => 'Sender function missing'], 500);
}