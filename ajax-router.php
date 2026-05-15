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
$mock_request = new WP_REST_Request('POST', '/mock/route');
if (!empty($data)) {
    $mock_request->set_body_params($data);
}
if (!empty($_GET)) {
    $mock_request->set_query_params($_GET);
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
        $result = rk_buy_ticket_api($mock_request); 
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
        // Assuming rk_process_deposit_api exists in api-financials.php
        $result = rk_process_deposit_api($mock_request);
        rk_send_response($result);
        break;

    case 'process_withdrawal':
        if (!is_user_logged_in()) rk_send_response(new WP_Error('unauthorized', 'Please log in.', ['status' => 401]));
        $result = rk_process_withdrawal_api($mock_request);
        rk_send_response($result);
        break;

    // ==========================================
    // FALLBACK
    // ==========================================
    default:
        rk_send_response(new WP_Error('invalid_action', 'Endpoint action not found.', ['status' => 404]));
        break;
}