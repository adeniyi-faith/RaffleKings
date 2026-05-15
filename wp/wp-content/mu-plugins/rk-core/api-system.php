<?php
/**
 * Module: System, Utilities, Logging & Notifications
 * Core shared functions for the API architecture.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==========================================
// *** SECURITY HELPER: ENHANCED RATE LIMITING ***
// ==========================================
function rk_check_rate_limit($action, $limit = 5, $seconds = 60) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $user_id = get_current_user_id();
    
    $keys = ['rk_rate_ip_' . $action . '_' . md5($ip)];
    if ($user_id) {
        $keys[] = 'rk_rate_user_' . $action . '_' . $user_id;
    }
    
    foreach ($keys as $key) {
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            $penalty_time = min($seconds * pow(2, floor($count / $limit)), 3600);
            set_transient($key, $count + 1, $penalty_time);
            
            return new WP_Error('rate_limit', 
                "Too many requests. Please wait " . ceil($penalty_time / 60) . " minutes.", 
                ['status' => 429]
            );
        }
        set_transient($key, $count + 1, $seconds);
    }
    
    return true;
}

// ==========================================
// *** SYSTEM LOGGING ***
// ==========================================
function rk_handle_system_log($request) {
    if ($request->get_method() === 'OPTIONS') return new WP_REST_Response(null, 200);
    global $wpdb;
    $params = $request->get_json_params();
    $user_id = isset($params['user_id']) ? intval($params['user_id']) : 0;
    $type = sanitize_text_field($params['type'] ?? 'General Error');
    $msg = sanitize_textarea_field($params['message'] ?? 'Unknown Error');
    $file = sanitize_text_field($params['file'] ?? 'unknown');
    $line = sanitize_text_field($params['line'] ?? '0');
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $wpdb->insert($wpdb->prefix . 'raffle_error_logs', [
        'user_id' => $user_id, 'error_type' => $type, 'error_message' => substr($msg, 0, 1000), 'source_file' => substr($file, -50), 'line_number' => $line, 'user_agent' => $ua
    ]);
    return ['success' => true];
}

// ==========================================
// *** EMAIL SYSTEM ***
// ==========================================
function rk_get_email_html($heading, $body, $btn_text = '', $btn_url = '') {
    $logo_url = 'https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS.png'; 
    $brand_color = '#007AFF';
    $bg_color = '#f4f4f4';

    $html = "
    <!DOCTYPE html>
    <html>
    <body style='margin:0; padding:0; background-color:$bg_color; font-family:-apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Arial, sans-serif;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0'>
            <tr>
                <td align='center' style='padding: 20px;'>
                    <table width='600' border='0' cellspacing='0' cellpadding='0' style='background:white; border-radius:12px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.08);'>
                        <tr>
                            <td align='center' style='background:linear-gradient(135deg, $brand_color 0%, #0051D5 100%); padding:30px 20px;'>
                                <img src='$logo_url' width='120' alt='RaffleKings' style='display:block;'>
                            </td>
                        </tr>
                        <tr>
                            <td style='padding:40px 30px; color:#333;'>
                                <h1 style='margin:0 0 20px; font-size:26px; color:#1a1a1a; font-weight:600;'>$heading</h1>
                                <div style='font-size:16px; line-height:1.7; color:#555;'>$body</div>";

    if ($btn_text && $btn_url) {
        $html .= "<div style='margin-top:32px; text-align:center;'>
                    <a href='$btn_url' style='background:$brand_color; color:white; padding:14px 32px; text-decoration:none; border-radius:8px; font-weight:600; display:inline-block;'>$btn_text</a>
                  </div>";
    }

    $current_year = date('Y');
    $html .= "</td></tr>
            <tr>
                <td style='background:#f8f9fa; padding:24px; text-align:center; font-size:13px; color:#6c757d; border-top:1px solid #e9ecef;'>
                    <p style='margin:0 0 8px;'>&copy; $current_year RaffleKings. All rights reserved.</p>
                </td>
            </tr>
        </table></td></tr></table></body></html>";
    return $html;
}

function rk_send_email($to, $subject, $message) {
    // 1. Throttling: Max 300 emails/day
    $count_key = 'rk_email_count_' . date('Y-m-d');
    $current_count = (int) get_transient($count_key);
    
    if ($current_count >= 300) {
        error_log("RK Email Limit Reached: $current_count sent today");
        return false;
    }

    add_filter('wp_mail_content_type', function() { return "text/html"; });
    $sent = wp_mail($to, $subject, $message);
    
    if ($sent) {
        set_transient($count_key, $current_count + 1, DAY_IN_SECONDS);
    }
    
    return $sent;
}

// ==========================================
// *** TELEGRAM INTEGRATION (FIXED) ***
// ==========================================

/**
 * FIXED: Sends Text Alert (With Deduplication)
 * Prevents double-notifications if webhook fires twice.
 */
function rk_send_telegram_alert($message, $photo_url = null) {
    // 1. DEDUPLICATION (Fix for Double Notifications)
    // Create a unique hash of the message content
    $msg_hash = 'rk_tg_debounce_' . md5($message . ($photo_url ?? ''));
    if (get_transient($msg_hash)) {
        return true; // Already sent in last 60s, skip gracefully
    }

    $token = defined('RK_TELEGRAM_BOT_TOKEN') ? RK_TELEGRAM_BOT_TOKEN : '';
    $chat_ids = defined('RK_TELEGRAM_ADMIN_IDS') ? RK_TELEGRAM_ADMIN_IDS : '';

    if (empty($token) || empty($chat_ids)) return false;

    $ids = array_map('trim', explode(',', $chat_ids));
    $success = false;

    // Use Photo function if URL provided
    if ($photo_url) {
        return rk_send_telegram_photo($photo_url, $message);
    }

    foreach ($ids as $chat_id) {
        $url = "https://api.telegram.org/bot$token/sendMessage";
        $body = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];
        
        $response = wp_remote_post($url, ['body' => $body, 'timeout' => 10]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $success = true;
        }
    }
    
    // Lock this message for 60 seconds to prevent duplicates
    if ($success) {
        set_transient($msg_hash, 1, 60);
    }

    return $success;
}

/**
 * NEW: Send Photo Receipt to Telegram
 * Used by Manual Deposit system to show receipts in chat.
 */
function rk_send_telegram_photo($photo_url, $caption = '') {
    // Deduplication check for photos too
    $msg_hash = 'rk_tg_photo_debounce_' . md5($photo_url . $caption);
    if (get_transient($msg_hash)) return true;

    $token = defined('RK_TELEGRAM_BOT_TOKEN') ? RK_TELEGRAM_BOT_TOKEN : '';
    $chat_ids = defined('RK_TELEGRAM_ADMIN_IDS') ? RK_TELEGRAM_ADMIN_IDS : '';

    if (empty($token) || empty($chat_ids)) return false;

    $ids = array_map('trim', explode(',', $chat_ids));
    $success = false;

    foreach ($ids as $chat_id) {
        $url = "https://api.telegram.org/bot$token/sendPhoto";
        
        $response = wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'photo' => $photo_url,
                'caption' => $caption,
                'parse_mode' => 'HTML'
            ],
            'timeout' => 20 // Photos take longer
        ]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $success = true;
        }
    }

    // Lock to prevent duplicate uploads
    if ($success) {
        set_transient($msg_hash, 1, 60);
    }
    
    return $success;
}
?>