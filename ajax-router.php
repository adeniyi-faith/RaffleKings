<?php
/**
 * RaffleKings Local AJAX Router
 * Bypasses REST API for instant data fetching and native cookie authentication.
 * * Location: Place this file in your Custom PHP frontend root folder.
 */

// 1. Boot up WordPress silently
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false); // Skips loading the theme for maximum performance
require_once(__DIR__ . '/wp/wp-load.php');

// 2. Prepare JSON Response Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Adjust if needed

// 3. Read the incoming Request Data
$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);

// Fallback to $_POST if not JSON
if (!$data && !empty($_POST)) {
    $data = $_POST;
}

$action = $data['action'] ?? $_GET['action'] ?? '';

// 4. Helper: Unwrap WP_REST_Response
// Since your old functions return WP_REST_Response, this helper unwraps them automatically!
function rk_send_response($response) {
    if (is_wp_error($response)) {
        echo json_encode([
            'success' => false,
            'message' => $response->get_error_message(),
            'code' => $response->get_error_code()
        ]);
    } elseif (class_exists('WP_REST_Response') && $response instanceof WP_REST_Response) {
        http_response_code($response->get_status());
        echo wp_json_encode($response->get_data());
    } else {
        echo wp_json_encode($response);
    }
    exit;
}

// 5. Mock a WP_REST_Request (To trick your existing functions into working)
$mock_request = new WP_REST_Request($_SERVER['REQUEST_METHOD'], '/mock/route');
if (!empty($data)) {
    $mock_request->set_body_params($data);
}
if (!empty($_GET)) {
    $mock_request->set_query_params($_GET);
}
if (!empty($_FILES)) {
    $mock_request->set_file_params($_FILES);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Some wp functions check for get_json_params or the raw body
    $mock_request->set_body($request_body);
}

// 6. Route the Request
switch ($action) {

    // ==========================================
    // AUTHENTICATION ROUTES (Native Cookies)
    // ==========================================
    case 'login':
        $username = sanitize_user($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($username) || empty($password)) {
            rk_send_response(new WP_Error('empty_fields', 'Username and password are required.'));
        }

        $user = wp_signon([
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true
        ], is_ssl());

        if (is_wp_error($user)) {
            rk_send_response(new WP_Error('login_failed', strip_tags($user->get_error_message())));
        } else {
            rk_send_response([
                'success' => true,
                'message' => 'Login successful',
                'user' => ['id' => $user->ID, 'name' => $user->display_name, 'email' => $user->user_email]
            ]);
        }
        break;

    case 'logout':
        wp_logout();
        rk_send_response(['success' => true, 'message' => 'Logged out successfully']);
        break;

    // ==========================================
    // GAMIFICATION & RAFFLES
    // ==========================================
    case 'get_hall_of_fame':
        // Calls your exact function from api-gamification.php
        $result = rk_get_hall_of_fame();
        rk_send_response($result);
        break;

    case 'buy_ticket':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        // Assuming your existing function expects a WP_REST_Request object
        $result = rk_handle_cart_sync($mock_request);
        rk_send_response($result);
        break;

    // ==========================================
    // FINANCIALS & WALLET
    // ==========================================
    case 'get_balances':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));

        $user_id = get_current_user_id();
        rk_send_response([
            'success' => true,
            'wallet' => get_user_meta($user_id, 'wallet_balance', true) ?: 0,
            'earnings' => get_user_meta($user_id, 'earnings_balance', true) ?: 0
        ]);
        break;

    case 'process_deposit':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        // Assuming rk_handle_payment_ai exists in api-financials.php
        $result = rk_handle_payment_ai($mock_request);
        rk_send_response($result);
        break;

    case 'process_withdrawal':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        $result = rk_handle_withdrawal($mock_request);
        rk_send_response($result);
        break;

    // ==========================================
    // FALLBACK
    // ==========================================

    // ==========================================
    // MIGRATED ROUTES
    // ==========================================
    case 'register':
        rk_send_response(rk_handle_new_registration($mock_request));
        break;
    case 'forgot_password':
        rk_send_response(rk_handle_forgot_password($mock_request));
        break;
    case 'reset_password':
        rk_send_response(rk_handle_reset_password($mock_request));
        break;
    case 'get_profile':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_handle_profile_request($mock_request));
        break;
    case 'cart_sync':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_handle_cart_sync($mock_request));
        break;
    case 'draw_results':
        rk_send_response(rk_get_draw_results($mock_request));
        break;
    case 'system_log':
        rk_send_response(rk_handle_system_log($mock_request));
        break;
    case 'get_tutorials':
        rk_send_response(rk_get_tutorials($mock_request));
        break;
    case 'tutorial_helpful':
        rk_send_response(rk_tutorial_mark_helpful($mock_request));
        break;
    case 'get_site_notices':
        rk_send_response(rk_get_site_notices($mock_request));
        break;
    case 'live_comments':
        rk_send_response(rk_get_live_comments($mock_request));
        break;
    case 'post_live_comment':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_post_live_comment($mock_request));
        break;
    case 'bank_accounts':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            rk_send_response(rk_save_bank_account($mock_request));
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            rk_send_response(rk_delete_bank_account($mock_request));
        } else {
            rk_send_response(rk_get_bank_accounts($mock_request));
        }
        break;
    case 'save_device':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_save_push_device($mock_request));
        break;
    case 'transfer':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_handle_transfer($mock_request));
        break;
    case 'transactions':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_get_user_transactions($mock_request));
        break;
    case 'apply_discount':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_apply_recovery_discount($mock_request));
        break;
    case 'get_tickets':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_get_user_tickets($mock_request));
        break;
    case 'claim_daily':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_handle_daily_claim($mock_request));
        break;
    case 'rewards_state':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_get_rewards_state($mock_request));
        break;
    case 'claim_task':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_handle_task_claim($mock_request));
        break;
    case 'referral_stats':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_get_referral_stats($mock_request));
        break;
    case 'spin_wheel':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_execute_spin_logic($mock_request));
        break;
    case 'redeem_points':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        rk_send_response(rk_handle_redeem_points($mock_request));
        break;

    case 'get_settings':
        rk_send_response([
            'bank_name' => get_option('rk_bank_name'),
            'account_number' => get_option('rk_account_number'),
            'account_name' => get_option('rk_account_name'),
            'paystack_public_key' => get_option('rk_paystack_public')
        ]);
        break;

    case 'get_raffles':
        $req = new WP_REST_Request('GET', '/wp/v2/raffle');
        if (isset($_GET['per_page'])) $req->set_param('per_page', intval($_GET['per_page']));
        if (isset($_GET['status'])) $req->set_param('status', sanitize_text_field($_GET['status']));
        if (isset($_GET['_embed'])) $req->set_param('_embed', 1);
        $res = rest_do_request($req);
        if ($res->is_error()) rk_send_response($res);
        else {
            $server = rest_get_server();
            rk_send_response($server->response_to_data($res, isset($_GET['_embed'])));
        }
        break;

    case 'get_raffle_by_id':
        $id = intval($_GET['id'] ?? 0);
        $req = new WP_REST_Request('GET', "/wp/v2/raffle/$id");
        if (isset($_GET['_embed'])) $req->set_param('_embed', 1);
        $res = rest_do_request($req);
        if ($res->is_error()) rk_send_response($res);
        else {
            $server = rest_get_server();
            rk_send_response($server->response_to_data($res, isset($_GET['_embed'])));
        }
        break;

    default:
        rk_send_response(new WP_Error('invalid_action', 'Endpoint action not found.', ['status' => 404]));
        break;
}