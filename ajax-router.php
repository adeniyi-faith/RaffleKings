<?php
/**
 * RaffleKings Phase 1 same-origin AJAX router.
 *
 * Frontend pages must call this file instead of the remote WordPress REST host. The
 * router bootstraps the local WordPress install and dispatches to the existing
 * backend functions directly, returning a consistent JSON envelope.
 */

define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$raw_body = file_get_contents('php://input');
$body_data = json_decode($raw_body, true);
if (!is_array($body_data)) {
    $body_data = $_POST ?: [];
}

$action = sanitize_key($body_data['action'] ?? $_GET['action'] ?? '');

function rk_ajax_envelope($success, $data = null, $message = '', $code = 'ok', $status = 200) {
    if (!headers_sent()) {
        http_response_code($status);
    }

    $payload = [
        'success' => (bool) $success,
        'data' => $data,
        'message' => $message,
        'code' => $code,
    ];

    // Compatibility for older frontend code that reads fields directly.
    if (is_array($data) && array_keys($data) !== range(0, count($data) - 1)) {
        $payload = array_merge($data, $payload);
    }

    echo wp_json_encode($payload);
    exit;
}

function rk_ajax_error($message, $code = 'error', $status = 400, $data = null) {
    rk_ajax_envelope(false, $data, $message, $code, $status);
}

function rk_ajax_send($response, $default_message = '') {
    $status = 200;

    if (is_wp_error($response)) {
        $error_data = $response->get_error_data();
        $status = (is_array($error_data) && isset($error_data['status'])) ? (int) $error_data['status'] : 400;
        rk_ajax_envelope(false, null, $response->get_error_message(), $response->get_error_code(), $status ?: 400);
    }

    if ($response instanceof WP_REST_Response) {
        $status = (int) $response->get_status();
        $response = $response->get_data();
    }

    $success = true;
    $message = $default_message;
    $code = 'ok';
    $data = $response;

    if (is_array($response)) {
        if (array_key_exists('success', $response)) {
            $success = (bool) $response['success'];
        }
        if (isset($response['message'])) {
            $message = (string) $response['message'];
        }
        if (isset($response['code'])) {
            $code = (string) $response['code'];
        } elseif (!$success) {
            $code = 'error';
        }
    }

    rk_ajax_envelope($success, $data, $message, $code, $status ?: 200);
}

function rk_ajax_require_login() {
    if (!is_user_logged_in()) {
        rk_ajax_error('Please log in.', 'unauthorized', 401);
    }
}

function rk_ajax_request($method = 'POST', $route = '/rk/local') {
    global $body_data, $raw_body;

    $request = new WP_REST_Request($method, $route);
    $params = is_array($body_data) ? $body_data : [];
    unset($params['action']);

    $request->set_query_params(array_merge($_GET, $params));
    $request->set_body_params($params);
    $request->set_file_params($_FILES);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body($raw_body ?: wp_json_encode($params));

    return $request;
}

function rk_ajax_site_settings() {
    return [
        'bank_name' => get_option('rk_bank_name'),
        'account_number' => get_option('rk_account_number'),
        'account_name' => get_option('rk_account_name'),
        'turnstile_site_key' => defined('RK_TURNSTILE_SITE_KEY') ? RK_TURNSTILE_SITE_KEY : '',
    ];
}

function rk_ajax_format_raffle($post) {
    if (!$post instanceof WP_Post) {
        $post = get_post($post);
    }
    if (!$post || $post->post_type !== 'raffle') {
        return null;
    }

    $meta = function_exists('rk_get_raffle_meta') ? rk_get_raffle_meta(['id' => $post->ID]) : [];

    return [
        'id' => $post->ID,
        'date' => get_post_time('c', true, $post),
        'slug' => $post->post_name,
        'status' => $post->post_status,
        'link' => 'raffle-details.php?id=' . $post->ID,
        'title' => ['rendered' => get_the_title($post)],
        'content' => ['rendered' => apply_filters('the_content', $post->post_content)],
        'excerpt' => ['rendered' => get_the_excerpt($post)],
        'featured_media_url' => get_the_post_thumbnail_url($post, 'large') ?: '',
        'raffle_meta' => $meta,
    ];
}

function rk_ajax_get_raffles() {
    $per_page = min(100, max(1, (int) ($_GET['per_page'] ?? 100)));
    $posts = get_posts([
        'post_type' => 'raffle',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    return array_values(array_filter(array_map('rk_ajax_format_raffle', $posts)));
}

function rk_ajax_get_raffle() {
    global $body_data;
    $id = (int) ($body_data['id'] ?? $_GET['id'] ?? $_GET['raffle_id'] ?? 0);
    if (!$id) {
        return new WP_Error('missing_raffle_id', 'Raffle ID is required.', ['status' => 400]);
    }

    $raffle = rk_ajax_format_raffle(get_post($id));
    if (!$raffle) {
        return new WP_Error('raffle_not_found', 'Raffle not found.', ['status' => 404]);
    }

    return $raffle;
}

function rk_ajax_login() {
    global $body_data;
    $username = sanitize_user($body_data['username'] ?? $body_data['email'] ?? '');
    $password = (string) ($body_data['password'] ?? '');

    if ($username === '' || $password === '') {
        return new WP_Error('empty_fields', 'Username and password are required.', ['status' => 400]);
    }

    $user = wp_signon([
        'user_login' => $username,
        'user_password' => $password,
        'remember' => true,
    ], is_ssl());

    if (is_wp_error($user)) {
        return new WP_Error('login_failed', wp_strip_all_tags($user->get_error_message()), ['status' => 401]);
    }

    if (function_exists('rk_check_user_status')) {
        $status = rk_check_user_status($user->ID, 'general');
        if (is_wp_error($status)) {
            wp_logout();
            return $status;
        }
    }

    $next = function_exists('rk_auth_safe_return_url') ? rk_auth_safe_return_url('index.php') : 'index.php';

    return [
        'success' => true,
        'message' => 'Login successful',
        'token' => wp_create_nonce('rk_ajax_session'),
        'redirect' => $next,
        'auth' => function_exists('rk_auth_cookie_params') ? rk_auth_cookie_params() : ['logged_in' => true, 'user_id' => $user->ID],
        'user' => [
            'id' => $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
        ],
    ];
}

$routes = [
    'register' => ['public' => true, 'method' => 'POST', 'callback' => fn() => rk_handle_new_registration(rk_ajax_request('POST', '/lottery/v1/register'))],
    'login' => ['public' => true, 'method' => 'POST', 'callback' => 'rk_ajax_login'],
    'logout' => ['public' => true, 'method' => 'POST', 'callback' => function() { wp_logout(); return ['success' => true, 'message' => 'Logged out successfully']; }],
    'forgot_password' => ['public' => true, 'method' => 'POST', 'callback' => fn() => rk_handle_forgot_password(rk_ajax_request('POST', '/raffle/v1/auth/forgot-password'))],
    'reset_password' => ['public' => true, 'method' => 'POST', 'callback' => fn() => rk_handle_reset_password(rk_ajax_request('POST', '/raffle/v1/auth/reset-password'))],
    'verify_reset_code' => ['public' => true, 'method' => 'POST', 'callback' => fn() => rk_handle_verify_reset_code(rk_ajax_request('POST', '/raffle/v1/auth/reset-password'))],
    'resend_reset_code' => ['public' => true, 'method' => 'POST', 'callback' => fn() => rk_handle_forgot_password(rk_ajax_request('POST', '/raffle/v1/auth/forgot-password'))],
    'get_settings' => ['public' => true, 'method' => 'GET', 'callback' => 'rk_ajax_site_settings'],
    'get_raffles' => ['public' => true, 'method' => 'GET', 'callback' => 'rk_ajax_get_raffles'],
    'get_raffle' => ['public' => true, 'method' => 'GET', 'callback' => 'rk_ajax_get_raffle'],
    'hall_of_fame' => ['public' => true, 'method' => 'GET', 'callback' => 'rk_get_hall_of_fame'],
    'get_hall_of_fame' => ['public' => true, 'method' => 'GET', 'callback' => 'rk_get_hall_of_fame'],
    'draw_results' => ['public' => true, 'method' => 'GET', 'callback' => fn() => rk_get_draw_results(rk_ajax_request('GET', '/raffle/v1/draw/results'))],
    'live_comments' => ['public' => true, 'method' => 'GET', 'callback' => fn() => rk_get_live_comments(rk_ajax_request('GET', '/raffle/v1/live/comments'))],
    'tutorials' => ['public' => true, 'method' => 'GET', 'callback' => fn() => rk_get_tutorials(rk_ajax_request('GET', '/raffle/v1/tutorials'))],
    'tutorial_helpful' => ['public' => true, 'method' => 'POST', 'callback' => fn() => rk_tutorial_mark_helpful(rk_ajax_request('POST', '/raffle/v1/tutorials/helpful'))],
    'site_notices' => ['public' => true, 'method' => 'GET', 'callback' => fn() => rk_get_site_notices(rk_ajax_request('GET', '/raffle/v1/site-notices'))],
    'system_log' => ['public' => true, 'method' => 'POST', 'callback' => fn() => rk_handle_system_log(rk_ajax_request('POST', '/raffle/v1/system/log'))],
    'post_live_comment' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_post_live_comment(rk_ajax_request('POST', '/raffle/v1/live/comment'))],
    'get_profile' => ['public' => false, 'method' => 'GET', 'callback' => fn() => rk_get_user_profile(rk_ajax_request('GET', '/raffle/v1/profile'))],
    'update_profile' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_update_user_profile(rk_ajax_request('POST', '/raffle/v1/profile'))],
    'get_balance' => ['public' => false, 'method' => 'GET', 'callback' => 'rk_get_balance'],
    'balance' => ['public' => false, 'method' => 'GET', 'callback' => 'rk_get_balance'],
    'cart_sync' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_cart_sync(rk_ajax_request('POST', '/raffle/v1/cart/sync'))],
    'buy_ticket' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_payment_ai(rk_ajax_request('POST', '/raffle/v1/payment'))],
    'payment' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_payment_ai(rk_ajax_request('POST', '/raffle/v1/payment'))],
    'transfer' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_transfer(rk_ajax_request('POST', '/raffle/v1/transfer'))],
    'withdrawal' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_withdrawal(rk_ajax_request('POST', '/raffle/v1/withdraw'))],
    'withdraw' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_withdrawal(rk_ajax_request('POST', '/raffle/v1/withdraw'))],
    'bank_accounts' => ['public' => false, 'method' => 'GET', 'callback' => fn() => rk_get_bank_accounts(rk_ajax_request('GET', '/raffle/v1/bank-accounts'))],
    'save_bank_account' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_save_bank_account(rk_ajax_request('POST', '/raffle/v1/bank-accounts'))],
    'delete_bank_account' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_delete_bank_account(rk_ajax_request('DELETE', '/raffle/v1/bank-accounts'))],
    'transactions' => ['public' => false, 'method' => 'GET', 'callback' => fn() => rk_get_user_transactions(rk_ajax_request('GET', '/raffle/v1/transactions'))],
    'user_tickets' => ['public' => false, 'method' => 'GET', 'callback' => fn() => rk_get_user_tickets(rk_ajax_request('GET', '/raffle/v1/tickets'))],
    'tickets' => ['public' => false, 'method' => 'GET', 'callback' => fn() => rk_get_user_tickets(rk_ajax_request('GET', '/raffle/v1/tickets'))],
    'rewards_state' => ['public' => false, 'method' => 'GET', 'callback' => fn() => rk_get_rewards_state(rk_ajax_request('GET', '/raffle/v1/rewards-state'))],
    'spin' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_execute_spin_logic(rk_ajax_request('POST', '/raffle/v1/spin-wheel'))],
    'daily_claim' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_daily_claim(rk_ajax_request('POST', '/raffle/v1/claim-daily'))],
    'task_claim' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_task_claim(rk_ajax_request('POST', '/raffle/v1/claim-task'))],
    'redeem_points' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_handle_redeem_points(rk_ajax_request('POST', '/raffle/v1/redeem-points'))],
    'referral_stats' => ['public' => false, 'method' => 'GET', 'callback' => fn() => rk_get_referral_stats(rk_ajax_request('GET', '/raffle/v1/referral-stats'))],
    'push_device_save' => ['public' => false, 'method' => 'POST', 'callback' => fn() => rk_save_push_device(rk_ajax_request('POST', '/raffle/v1/save-device'))],
];

if (!$action || !isset($routes[$action])) {
    rk_ajax_error('Endpoint action not found.', 'invalid_action', 404);
}

$route = $routes[$action];
if (empty($route['public'])) {
    rk_ajax_require_login();
}

if (!is_callable($route['callback'])) {
    rk_ajax_error('Endpoint handler is unavailable.', 'handler_missing', 500);
}

rk_ajax_send(call_user_func($route['callback']));
