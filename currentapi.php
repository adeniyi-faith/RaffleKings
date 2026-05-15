<?php
/**
 * REST API Endpoints and Core Business Logic
 * Extracted from functions.php
 */

// *** CRITICAL FIX FOR HEADERLESS SITES: CORS HANDLER ***
add_action('init', function() {
    $allowed_origins = ['https://rafflekings.com.ng', 'https://www.rafflekings.com.ng'];
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

// 1. AVATAR FILTER
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

// 2. API INIT
add_action('rest_api_init', function () {
    // A. Hall of Fame (Winners) - UPDATED
    register_rest_route('raffle/v1', '/hall-of-fame', [
        'methods' => 'GET',
        'callback' => 'rk_get_hall_of_fame',
        'permission_callback' => '__return_true'
    ]);
    
    // ... existing routes ...
    register_rest_route('raffle/v1', '/balance', [
        'methods' => 'GET',
        'callback' => 'rk_get_balance',
        'permission_callback' => 'rk_check_auth'
    ]);
    
    // Settings
    register_rest_route('raffle/v1', '/settings', ['methods'=>'GET', 'callback'=>function(){ return ['bank_name'=>get_option('rk_bank_name'), 'account_number'=>get_option('rk_account_number'), 'account_name'=>get_option('rk_account_name')]; }, 'permission_callback'=>'__return_true']);
    register_rest_route('lottery/v1', '/register', ['methods'=>'POST', 'callback'=>'rk_handle_new_registration', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/profile', ['methods'=>['GET','POST'], 'callback'=>'rk_handle_profile_request', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/bank-accounts', ['methods'=>'GET', 'callback'=>'rk_get_bank_accounts', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/bank-accounts', ['methods'=>'POST', 'callback'=>'rk_save_bank_account', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/bank-accounts', ['methods'=>'DELETE', 'callback'=>'rk_delete_bank_account', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/save-device', ['methods'=>'POST', 'callback'=>'rk_save_push_device', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/payment', ['methods'=>'POST', 'callback'=>'rk_handle_payment_ai', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/transfer', ['methods'=>'POST', 'callback'=>'rk_handle_transfer', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/withdraw', ['methods'=>'POST', 'callback'=>'rk_handle_withdrawal', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/transactions', ['methods'=>'GET', 'callback'=>'rk_get_user_transactions', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/cart/sync', ['methods'=>'POST', 'callback'=>'rk_handle_cart_sync', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/tickets', ['methods'=>'GET', 'callback'=>'rk_get_user_tickets', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/claim-daily', ['methods'=>'POST', 'callback'=>'rk_handle_daily_claim', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/rewards-state', ['methods'=>'GET', 'callback'=>'rk_get_rewards_state', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/claim-task', ['methods'=>'POST', 'callback'=>'rk_handle_task_claim', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/referral-stats', ['methods'=>'GET', 'callback'=>'rk_get_referral_stats', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/live/comment', ['methods'=>'POST', 'callback'=>'rk_post_live_comment', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/live/comments', ['methods'=>'GET', 'callback'=>'rk_get_live_comments', 'permission_callback'=>'__return_true']);
    register_rest_route('raffle/v1', '/system/log', ['methods'=>['POST', 'OPTIONS'], 'callback'=>'rk_handle_system_log', 'permission_callback'=>function(){ return true; }]);

    // *** UPDATED: Spin & Win System ***
    register_rest_route('raffle/v1', '/spin-wheel', ['methods'=>'POST', 'callback'=>'rk_execute_spin_logic', 'permission_callback'=>'__return_true']);

    // *** UPDATED: Central Redemption ***
    register_rest_route('raffle/v1', '/redeem-points', ['methods'=>'POST', 'callback'=>'rk_handle_redeem_points', 'permission_callback'=>'__return_true']);

    // *** NEW: Tutorials Endpoints ***
    register_rest_route('raffle/v1', '/tutorials', [
        'methods' => 'GET',
        'callback' => 'rk_get_tutorials',
        'permission_callback' => '__return_true'
    ]);
    register_rest_route('raffle/v1', '/tutorials/helpful', [
        'methods' => 'POST',
        'callback' => 'rk_tutorial_mark_helpful',
        'permission_callback' => '__return_true'
    ]);

    // *** NEW: SITE ALERTS / NOTIFICATIONS ***
    register_rest_route('raffle/v1', '/site-notices', [
        'methods' => 'GET',
        'callback' => 'rk_get_site_notices',
        'permission_callback' => '__return_true'
    ]);
    
    // *** NEW: ACKNOWLEDGE WELCOME BONUS ***
    register_rest_route('raffle/v1', '/ack-welcome', [
        'methods' => 'POST',
        'callback' => 'rk_acknowledge_welcome_bonus',
        'permission_callback' => '__return_true'
    ]);

    // *** UPDATED DRAW ROUTES ***
    register_rest_route('raffle/v1', '/draw/run', [
        'methods' => 'POST', 
        'callback' => 'rk_run_raffle_draw', 
        'permission_callback' => function() { return current_user_can('manage_options'); }
    ]);
    register_rest_route('raffle/v1', '/draw/results', [
        'methods' => 'GET', 
        'callback' => 'rk_get_draw_results', 
        'permission_callback' => '__return_true'
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

    register_rest_field('raffle', 'raffle_meta', ['get_callback' => 'rk_get_raffle_meta']);
    register_rest_field('raffle', 'image_url', ['get_callback' => function($post) { return has_post_thumbnail($post['id']) ? get_the_post_thumbnail_url($post['id'], 'medium_large') : ''; }]);
});

/**
 * Endpoint: Get Winners for Hall of Fame
 * FIXED: Filters by is_visible, sorts properly, uses correct dates, and gets correct state
 */
function rk_get_hall_of_fame() {
    global $wpdb;
    $table_winners = $wpdb->prefix . 'raffle_winners';
    
    // Check if table exists to prevent crash
    if($wpdb->get_var("SHOW TABLES LIKE '$table_winners'") != $table_winners) {
        return new WP_REST_Response([
            'featured' => [], 
            'recent' => [], 
            'total_count' => 0
        ], 200);
    }

    // Fetch ONLY Visible winners (Limit 50)
    // Ordered by ID DESC is okay, or won_at DESC
    $winners = $wpdb->get_results("
        SELECT * FROM $table_winners 
        WHERE is_visible = 1
        ORDER BY won_at DESC, id DESC LIMIT 50
    ");

    $formatted = [];
    foreach($winners as $w) {
        $user = get_userdata($w->user_id);
        
        // --- GENERATE SYSTEM VERIFICATION HASH ---
        $raw_string = $w->ticket_number . $w->won_at . $w->user_id . 'rk_sys_verify';
        $full_hash = '0x' . hash('sha256', $raw_string);
        
        $prize_display = $w->prize_name;
        if (!empty($w->prize_cash_value) && $w->prize_cash_value > 0) {
            $prize_display = '₦' . number_format($w->prize_cash_value);
        }

        $user_state = get_user_meta($w->user_id, 'state_of_residence', true);
        if (empty($user_state)) {
            $user_state = 'Nigeria'; 
        }

        $formatted[] = [
            'name' => $user ? $user->display_name : 'Lucky Winner',
            'avatar' => get_avatar_url($w->user_id),
            'prize' => $prize_display,
            'ticket' => $w->ticket_number,
            'state' => $user_state,
            'time_ago' => human_time_diff(strtotime($w->won_at), current_time('timestamp')) . ' ago',
            'hash' => $full_hash,
            'short_hash' => substr($full_hash, 0, 6) . '...' . substr($full_hash, -4)
        ];
    }
    
    // Sort logic here (Highest Amount -> Featured)
    usort($formatted, function($a, $b) {
        $amtA = (float) filter_var($a['prize'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $amtB = (float) filter_var($b['prize'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        return $amtB <=> $amtA;
    });

    return new WP_REST_Response([
        'featured' => array_slice($formatted, 0, 5), // Top 5 by amount
        'recent' => array_slice($formatted, 5),      // The rest
        'total_count' => count($formatted)
    ], 200);
}

// ... existing helper functions (rk_check_auth, rk_get_balance) ...
function rk_check_auth($request) {
    // JWT Auth logic placeholder
    return is_user_logged_in();
}

function rk_get_balance() {
    $user_id = get_current_user_id();
    return [
        'wallet' => (float)get_user_meta($user_id, 'wallet_balance', true),
        'earnings' => (float)get_user_meta($user_id, 'earnings_balance', true)
    ];
}

// Helper Wrapper for Profile
function rk_handle_profile_request($request) {
    if ($request->get_method() === 'POST') return rk_update_user_profile($request);
    return rk_get_user_profile($request);
}

// *** NEW FUNCTION: Mark Welcome as Seen ***
function rk_acknowledge_welcome_bonus($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    update_user_meta($user_id, 'rk_has_seen_welcome', 1);
    return ['success' => true];
}

// *** NEW: FETCH SITE NOTICES ***
function rk_get_site_notices($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_site_notices';
    if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) return [];
    $results = $wpdb->get_results("SELECT * FROM $table WHERE is_active = 1 ORDER BY created_at DESC");
    foreach($results as &$row) {
        $row->id = (int) $row->id;
        $row->dismiss_sec = (int) $row->dismiss_sec;
    }
    return $results;
}

// *** UPDATED: UNLIMITED SPINS LOGIC (4 SLICES) ***
function rk_execute_spin_logic($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    global $wpdb;
    $cost = 50; // Cost is 50 Points
    
    // 1. Validate Balance
    $current_points = (int) get_user_meta($user_id, 'rk_points', true);
    if ($current_points < $cost) {
        return new WP_Error('insufficient_points', 'You need 50 points to spin.', ['status' => 400]);
    }

    // 2. Define 4 Weighted Outcomes
    $prizes = [
        [15,    600, 'loss'],   // 0: 60% Chance -> User Net -35
        [50,    300, 'tie'],    // 1: 30% Chance -> User Net 0
        [150,   80,  'win'],    // 2: 8% Chance  -> User Net +100
        [500,   20,  'jackpot'] // 3: 2% Chance  -> User Net +450 (10x Win)
    ];

    // 3. Determine Winner
    $rand = rand(1, 1000);
    $current_weight = 0;
    $won_entry = $prizes[0];
    $won_index = 0;

    foreach ($prizes as $index => $prize) {
        $current_weight += $prize[1];
        if ($rand <= $current_weight) {
            $won_entry = $prize;
            $won_index = $index;
            break;
        }
    }

    $won_points = $won_entry[0];

    // 4. Execute DB Transactions
    $new_balance = $current_points - $cost + $won_points;
    update_user_meta($user_id, 'rk_points', $new_balance);

    // 5. Log the Activity
    $table_points = $wpdb->prefix . 'raffle_point_logs';
    if($wpdb->get_var("SHOW TABLES LIKE '$table_points'") == $table_points) {
        $wpdb->insert($table_points, [
            'user_id' => $user_id,
            'activity_type' => 'spin_game',
            'points_amount' => ($won_points - $cost), // Logs the NET change
            'description' => "Spin: Won $won_points (Cost $cost)",
            'balance_after' => $new_balance
        ]);
    }

    return [
        'success' => true,
        'payout' => $won_points,
        'old_balance' => $current_points,
        'new_balance' => $new_balance,
        'visual_index' => $won_index,
        'is_unlimited' => true
    ];
}

// *** UPDATED: CENTRAL REDEMPTION LOGIC ***
function rk_handle_redeem_points($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    global $wpdb;
    $conversion_rate = 10; // 10 Points = 1 Naira
    $current_points = (int) get_user_meta($user_id, 'rk_points', true);
    
    if ($current_points < 100) return new WP_Error('low_points', 'Minimum redemption is 100 Points', ['status' => 400]);

    // Calculate Wallet Value
    $wallet_value = floor($current_points / $conversion_rate);

    // 1. Reset Points
    update_user_meta($user_id, 'rk_points', 0);

    // 2. Log to Point Logs (Lightweight Audit)
    $table_points = $wpdb->prefix . 'raffle_point_logs';
    if($wpdb->get_var("SHOW TABLES LIKE '$table_points'") == $table_points) {
        $wpdb->insert($table_points, [
            'user_id' => $user_id,
            'activity_type' => 'redemption',
            'points_amount' => -$current_points,
            'description' => "Redeemed $current_points pts for ₦$wallet_value",
            'balance_after' => 0
        ]);
    }

    // 3. Credit Wallet & Log Financial Transaction
    $current_wallet = (float) get_user_meta($user_id, 'wallet_balance', true) ?: 0;
    update_user_meta($user_id, 'wallet_balance', $current_wallet + $wallet_value);

    $wpdb->insert($wpdb->prefix . 'raffle_transactions', [
        'user_id' => $user_id,
        'claimed_amount' => $wallet_value,
        'status' => 'verified_final',
        'type' => 'points_redemption',
        'proof_url' => 'internal_points',
        'txn_ref' => 'RDM-' . time() . '-' . $user_id,
        'created_at' => current_time('mysql')
    ]);

    return [
        'success' => true, 
        'redeemed_points' => $current_points, 
        'wallet_added' => $wallet_value, 
        'new_wallet_balance' => $current_wallet + $wallet_value
    ];
}

// *** NEW: ADVANCED DRAW LOGIC ***
function rk_run_raffle_draw($request) {
    global $wpdb;
    $params = $request->get_json_params();
    $raffle_id = intval($params['raffle_id']);

    if (!$raffle_id) return new WP_Error('missing_id', 'Raffle ID required', ['status' => 400]);

    // 1. Get Prize Structure (ACF Repeater)
    $prizes_config = [];
    if (function_exists('get_field')) {
        $rows = get_field('prize_structure', $raffle_id);
        if ($rows) {
            foreach ($rows as $row) {
                if (empty($row['tier_name'])) continue; // Skip empty tiers
                
                $count = intval($row['winner_count']);
                $cash = floatval($row['cash_value']);
                $desc = $row['prize_description'];
                
                for ($i = 0; $i < $count; $i++) {
                    $prizes_config[] = [
                        'name' => $row['tier_name'] . ($desc ? ': ' . $desc : ''),
                        'amount' => $cash,
                        'rank' => count($prizes_config) + 1 
                    ];
                }
            }
        }
    }

    if (empty($prizes_config)) return new WP_Error('no_prizes', 'No prize structure found. Configure Raffle Details first.', ['status' => 400]);

    // 2. Fetch Eligible Tickets
    $entries_table = $wpdb->prefix . 'raffle_entries';
    $all_tickets = $wpdb->get_results($wpdb->prepare("SELECT user_id, ticket_number FROM $entries_table WHERE raffle_id = %d", $raffle_id));

    if (empty($all_tickets)) return new WP_Error('no_entries', 'No tickets sold for this raffle.', ['status' => 400]);

    // 3. Apply Exclusion Rules (Cooldown: 3 Days)
    $winners_table = $wpdb->prefix . 'raffle_winners';
    $cooldown_days = 3; // UPDATED: Changed from 21 to 3 days per request
    $excluded_users = [];

    if($wpdb->get_var("SHOW TABLES LIKE '$winners_table'") == $winners_table) {
        $recent_winners = $wpdb->get_col("SELECT DISTINCT user_id FROM $winners_table WHERE won_at > DATE_SUB(NOW(), INTERVAL $cooldown_days DAY)");
        $excluded_users = array_merge($excluded_users, $recent_winners);
    }

    // Build Draw Pool
    $draw_pool = []; 
    foreach ($all_tickets as $ticket) {
        $uid = intval($ticket->user_id);
        if (in_array($uid, $excluded_users)) continue; // Skip cooldown users
        $draw_pool[] = ['uid' => $uid, 'ticket' => $ticket->ticket_number];
    }

    if (empty($draw_pool)) return new WP_Error('no_candidates', 'No eligible users found (all in cooldown or no tickets).', ['status' => 400]);

    // 4. Select Winners
    $winners_generated = [];
    $session_winners = []; // Track who won in THIS specific draw to prevent double wins
    
    shuffle($draw_pool); // Randomize

    foreach ($prizes_config as $prize) {
        $found_winner_index = -1;
        
        // Find a ticket belonging to a user who hasn't won yet in this session
        foreach ($draw_pool as $index => $entry) {
            if (!in_array($entry['uid'], $session_winners)) {
                $found_winner_index = $index;
                break;
            }
        }

        if ($found_winner_index === -1) break; // No more unique users available

        $winning_entry = $draw_pool[$found_winner_index];
        $uid = $winning_entry['uid'];
        
        $session_winners[] = $uid;
        
        // Remove this specific ticket from the pool to avoid picking it again
        array_splice($draw_pool, $found_winner_index, 1);

        $winners_generated[] = [
            'raffle_id' => $raffle_id,
            'user_id' => $uid,
            'ticket_number' => $winning_entry['ticket'],
            'prize_name' => $prize['name'],
            'prize_rank' => $prize['rank'],
            'amount' => $prize['amount']
        ];
    }

    // 5. Save Winners (Hidden by Default)
    foreach ($winners_generated as $win) {
        $wpdb->insert($winners_table, [
            'raffle_id' => $win['raffle_id'],
            'user_id' => $win['user_id'],
            'ticket_number' => $win['ticket_number'],
            'prize_name' => $win['prize_name'],
            'prize_rank' => $win['prize_rank'],
            'prize_cash_value' => $win['amount'],
            'won_at' => current_time('mysql'),
            'is_credited' => 0,
            'is_visible' => 0 // REQUIRE ADMIN APPROVAL
        ]);
    }

    update_post_meta($raffle_id, 'draw_status', 'active'); 
    return ['success' => true, 'winner_count' => count($winners_generated), 'message' => 'Winners generated successfully (Hidden). Go to Winners Manager to approve.'];
}

function rk_toggle_winner_visibility($request) {
    global $wpdb;
    $params = $request->get_json_params();
    $win_id = intval($params['win_id']);
    $visible = $params['visible'] ? 1 : 0;
    
    $wpdb->update($wpdb->prefix . 'raffle_winners', ['is_visible' => $visible], ['id' => $win_id]);
    return ['success' => true];
}

function rk_credit_raffle_winner($request) {
    global $wpdb;
    $params = $request->get_json_params();
    $win_id = intval($params['win_id']);
    $amount = floatval($params['amount']);

    if (!$win_id || $amount <= 0) return new WP_Error('invalid', 'Invalid ID or Amount', ['status' => 400]);

    $winners_table = $wpdb->prefix . 'raffle_winners';
    $winner_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $winners_table WHERE id = %d", $win_id));

    if (!$winner_record) return new WP_Error('not_found', 'Winner record not found', ['status' => 404]);
    if ($winner_record->is_credited) return new WP_Error('paid', 'Already Credited', ['status' => 400]);

    $user_id = $winner_record->user_id;
    $current_earnings = (float) get_user_meta($user_id, 'earnings_balance', true) ?: 0;
    
    update_user_meta($user_id, 'earnings_balance', $current_earnings + $amount);

    $wpdb->update($winners_table, ['is_credited' => 1], ['id' => $win_id]);

    $wpdb->insert($wpdb->prefix . 'raffle_transactions', [
        'user_id' => $user_id,
        'claimed_amount' => $amount,
        'status' => 'verified_final',
        'type' => 'prize_win',
        'proof_url' => 'admin_credit',
        'txn_ref' => 'WIN-' . $win_id,
        'created_at' => current_time('mysql')
    ]);

    return ['success' => true, 'message' => 'User Credited ₦' . number_format($amount)];
}

function rk_get_draw_results($request) {
    global $wpdb;
    $raffle_id = $request->get_param('raffle_id');
    
    if (!$raffle_id) {
        $args = [
            'post_type' => 'raffle', 
            'meta_query' => [
                'relation' => 'OR',
                ['key' => 'draw_status', 'value' => 'active'],
                ['key' => 'draw_status', 'value' => 'completed']
            ],
            'posts_per_page' => 1,
            'orderby' => 'modified'
        ];
        $posts = get_posts($args);
        if($posts) $raffle_id = $posts[0]->ID;
    }

    if (!$raffle_id) return ['status' => 'waiting', 'message' => 'No active draw'];

    $table = $wpdb->prefix . 'raffle_winners';
    if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) return ['status' => 'waiting', 'message' => 'System initializing'];

    // Only fetch VISIBLE winners for the frontend
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE raffle_id = %d AND is_visible = 1 ORDER BY prize_rank DESC", $raffle_id)); 

    $formatted = [];
    foreach ($results as $row) {
        $u = get_userdata($row->user_id);
        $avatar = get_user_meta($row->user_id, 'profile_pic_url', true) ?: get_avatar_url($row->user_id);
        
        $formatted[] = [
            'db_id' => $row->id, 
            'id' => str_pad($row->ticket_number, 4, '0', STR_PAD_LEFT),
            'name' => $u ? $u->display_name : 'Hidden User',
            'img' => $avatar,
            'prize' => $row->prize_name,
            'rank' => $row->prize_rank,
            'is_credited' => (bool)$row->is_credited 
        ];
    }

    // 2. FETCH PARTICIPANTS (For Live Spinner Visuals) - ADDED PER REQUEST
    // We fetch up to 70 random real ticket holders for this raffle to populate the scroll wheel.
    $entries_table = $wpdb->prefix . 'raffle_entries';
    $users_table = $wpdb->users;
    $participants = [];

    // Get REAL total entry count
    $total_entries = 0;
    if($wpdb->get_var("SHOW TABLES LIKE '$entries_table'") == $entries_table) {
        // First get the total count
        $total_entries = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $entries_table WHERE raffle_id = %d
        ", $raffle_id));

        // Then get the sample participants
        $pool_raw = $wpdb->get_results($wpdb->prepare("
            SELECT u.display_name, u.ID as user_id, e.ticket_number
            FROM $entries_table e
            JOIN $users_table u ON e.user_id = u.ID
            WHERE e.raffle_id = %d
            ORDER BY RAND()
            LIMIT 70
        ", $raffle_id));

        foreach($pool_raw as $p) {
            $p_avatar = get_user_meta($p->user_id, 'profile_pic_url', true) ?: get_avatar_url($p->user_id);
            $participants[] = [
                'name' => $p->display_name,
                'ticket' => str_pad($p->ticket_number, 4, '0', STR_PAD_LEFT),
                'avatar' => $p_avatar
            ];
        }
    }
    
    $status = get_post_meta($raffle_id, 'draw_status', true);
    
    return [
        'status' => $status,
        'raffle_id' => $raffle_id,
        'raffle_title' => get_the_title($raffle_id),
        'winners' => $formatted,
        'participants' => $participants,
        'total_pool_size' => (int)$total_entries // ADD THIS LINE
    ];
}

// Helper: Get Raffle Meta
function rk_get_raffle_meta($post) {
    $price = get_post_meta($post['id'], 'raffle_price', true);
    $max = get_post_meta($post['id'], 'max_tickets', true) ?: 1000;
    $expiry = get_post_meta($post['id'], 'expiry_date', true);
    
    // Initialize Grand Prize with legacy meta as fallback
    $grand_prize = get_post_meta($post['id'], 'grand_prize', true);
    
    // Flatten prizes for frontend if needed
    $prize_list = [];
    if (function_exists('get_field')) {
        $rows = get_field('prize_structure', $post['id']); 
        if($rows) { 
            // *** NEW LOGIC: Override Grand Prize with First Row ***
            if (isset($rows[0]) && !empty($rows[0]['prize_description'])) {
                 $grand_prize = $rows[0]['prize_description'];
            }
            // *** END LOGIC ***

            foreach($rows as $row) {
                $prize_list[] = $row['tier_name'] . ': ' . $row['prize_description'];
            }
        }
    }

    global $wpdb;
    $entries_table = $wpdb->prefix . 'raffle_entries';
    $sold = get_post_meta($post['id'], 'sold_tickets', true) ?: 0;
    $taken_numbers = [];
    
    if($wpdb->get_var("SHOW TABLES LIKE '$entries_table'") == $entries_table) {
        $sold_real = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $entries_table WHERE raffle_id = %d", $post['id']));
        $sold = max($sold_real, $sold); 
        $taken_numbers = $wpdb->get_col($wpdb->prepare("SELECT ticket_number FROM $entries_table WHERE raffle_id = %d", $post['id']));
    }

    $progress = ($max > 0) ? ($sold / $max) * 100 : 0;
    $remaining = $max - $sold;

    return [
        'price' => $price, 
        'tagline' => get_post_meta($post['id'], 'raffle_tagline', true), 
        'grand_prize' => $grand_prize, 
        'prize_list' => array_values($prize_list), 
        'sold' => $sold, 
        'max' => $max, 
        'remaining' => $remaining, 
        'progress' => round($progress), 
        'is_sold_out' => get_post_meta($post['id'], 'is_sold_out', true), 
        'expiry' => $expiry, 
        'winner' => get_post_meta($post['id'], 'raffle_winner', true), 
        'taken_numbers' => array_map('intval', $taken_numbers) 
    ];
}

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

function rk_handle_new_registration($request) {
    $params = $request->get_body_params();
    $files = $request->get_file_params(); 
    $username = sanitize_user($params['username']);
    $email = sanitize_email($params['email']);
    $password = $params['password'];
    $state = isset($params['state']) ? sanitize_text_field($params['state']) : '';
    if (username_exists($username)) return new WP_Error('exists', 'Username taken', ['status' => 400]);
    if (email_exists($email)) return new WP_Error('exists', 'Email taken', ['status' => 400]);
    if (empty($username) || empty($password)) return new WP_Error('missing', 'Missing fields', ['status' => 400]);
    
    $referrer_code = isset($params['referrer']) ? sanitize_text_field($params['referrer']) : '';

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) return new WP_Error('create_failed', $user_id->get_error_message(), ['status' => 500]);
    
    update_user_meta($user_id, 'wallet_balance', 300);
    update_user_meta($user_id, 'earnings_balance', 0);
    if(!empty($state)) update_user_meta($user_id, 'state_of_residence', $state);

    if (!empty($referrer_code)) {
        $referrer = get_user_by('login', $referrer_code) ?: get_user_by('email', $referrer_code);
        if ($referrer && $referrer->ID !== $user_id) {
            update_user_meta($user_id, 'referred_by', $referrer->ID);
            $count = (int) get_user_meta($referrer->ID, 'rk_referral_count', true);
            update_user_meta($referrer->ID, 'rk_referral_count', $count + 1);
            $current_pts = (int) get_user_meta($referrer->ID, 'rk_points', true);
            update_user_meta($referrer->ID, 'rk_points', $current_pts + 50);
        }
    }

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
    if (!$avatar_url) $avatar_url = get_avatar_url($user_id);
    
    // Optional: Set to 0 explicitly on registration so we know they haven't seen it
    update_user_meta($user_id, 'rk_has_seen_welcome', 0);
    
    return ['success' => true, 'user_id' => $user_id, 'message' => 'Account created', 'avatar_url' => $avatar_url];
}

function rk_get_user_profile($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    // Note: rk_check_user_status assumed to be defined externally or needs to be added if missing
    if (function_exists('rk_check_user_status')) {
        $status = rk_check_user_status($user_id);
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
        'has_seen_welcome' => $has_seen // <--- Added this field
    ];
}

function rk_update_user_profile($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
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

function rk_get_user_balance($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    $wallet = (float) get_user_meta($user_id, 'wallet_balance', true) ?: 0;
    if ($wallet == 0) {
        global $wpdb;
        $has_txns = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}raffle_transactions WHERE user_id = %d", $user_id));
        if ($has_txns == 0) { $wallet = 300; update_user_meta($user_id, 'wallet_balance', 300); }
    }
    return ['wallet' => $wallet, 'earnings' => (float) get_user_meta($user_id, 'earnings_balance', true) ?: 0];
}

function rk_get_bank_accounts($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    return get_user_meta($user_id, 'rk_bank_accounts', true) ?: [];
}

function rk_save_bank_account($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    $params = $request->get_json_params();
    if(empty($params['bank_name']) || empty($params['account_number']) || empty($params['account_name'])) return new WP_Error('missing_fields', 'All fields required', ['status' => 400]);
    $accounts = get_user_meta($user_id, 'rk_bank_accounts', true) ?: [];
    if (count($accounts) >= 2) return new WP_Error('limit_reached', 'Max 2 accounts allowed', ['status' => 400]);
    $new_account = ['id' => uniqid(), 'bank_name' => sanitize_text_field($params['bank_name']), 'account_number' => sanitize_text_field($params['account_number']), 'account_name' => sanitize_text_field($params['account_name']), 'is_primary' => count($accounts) === 0];
    $accounts[] = $new_account;
    update_user_meta($user_id, 'rk_bank_accounts', $accounts);
    return ['success' => true, 'accounts' => $accounts];
}

function rk_delete_bank_account($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    $id = $request->get_param('id');
    $accounts = get_user_meta($user_id, 'rk_bank_accounts', true) ?: [];
    $new_accounts = array_values(array_filter($accounts, function($acc) use ($id) { return $acc['id'] !== $id; }));
    if (!empty($new_accounts) && empty(array_filter($new_accounts, fn($a) => $a['is_primary']))) $new_accounts[0]['is_primary'] = true;
    update_user_meta($user_id, 'rk_bank_accounts', $new_accounts);
    return ['success' => true, 'accounts' => $new_accounts];
}

function rk_save_push_device($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    $params = $request->get_json_params();
    $player_id = sanitize_text_field($params['player_id']);
    if(empty($player_id)) return new WP_Error('missing_id', 'Device ID Missing', ['status' => 400]);
    $existing = get_user_meta($user_id, 'rk_onesignal_id', true);
    if($existing !== $player_id) update_user_meta($user_id, 'rk_onesignal_id', $player_id);
    return ['success' => true, 'message' => 'Device Registered'];
}

/**
 * Handles payment processing with "Fail-Safe" AI verification.
 * If AI fails (timeout, quota, network), it defaults to manual_review
 * instead of crashing or rejecting the user.
 */
function rk_handle_payment_ai($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    $params = $request->get_file_params();
    $amount = floatval($request->get_param('amount'));
    $type = $request->get_param('type'); 
    
    $numbers_input = $request->get_param('numbers');
    $numbers_str = '';
    if (is_array($numbers_input)) {
        $numbers_str = implode(',', $numbers_input);
    } else {
        $numbers_str = $numbers_input ? (string)$numbers_input : '';
    }

    $raffle_id = $request->get_param('raffle_id') ? intval($request->get_param('raffle_id')) : 0;
    $order_id = $request->get_param('order_id') ? sanitize_text_field($request->get_param('order_id')) : '';

    global $wpdb; 
    $table_txn = $wpdb->prefix . 'raffle_transactions';
    $table_entries = $wpdb->prefix . 'raffle_entries';

    // *** CASE 1: SPENDING WALLET PAYMENT ***
    if ($type === 'wallet_payment') {
        $current_bal = get_user_meta($user_id, 'wallet_balance', true) ?: 0;
        if ($current_bal < $amount) return new WP_Error('insufficient_funds', 'Insufficient balance.', ['status' => 400]);
        update_user_meta($user_id, 'wallet_balance', $current_bal - $amount);
        $wpdb->insert($table_txn, ['user_id' => $user_id, 'claimed_amount' => $amount, 'status' => 'verified_final', 'type' => 'ticket_purchase_wallet', 'proof_url' => 'wallet_debit', 'created_at' => current_time('mysql')]);
        $txn_id = $wpdb->insert_id;
        
        if ($raffle_id > 0 && !empty($numbers_str)) {
            $numbers = explode(',', $numbers_str);
            foreach ($numbers as $num) {
                $num = intval(trim($num));
                if ($num > 0) $wpdb->insert($table_entries, ['user_id' => $user_id, 'raffle_id' => $raffle_id, 'ticket_number' => $num, 'txn_id' => $txn_id, 'created_at' => current_time('mysql')]);
            }
        }
        return ['success' => true, 'message' => 'Success', 'new_balance' => $current_bal - $amount];
    }

    // *** CASE 2: EARNINGS/BONUS WALLET PAYMENT (RESTORED) ***
    if ($type === 'earnings_payment') {
        $current_earn = get_user_meta($user_id, 'earnings_balance', true) ?: 0;
        if ($current_earn < $amount) return new WP_Error('insufficient_funds', 'Insufficient winnings/bonus balance.', ['status' => 400]);
        
        update_user_meta($user_id, 'earnings_balance', $current_earn - $amount);
        
        $wpdb->insert($table_txn, [
            'user_id' => $user_id, 
            'claimed_amount' => $amount, 
            'status' => 'verified_final', 
            'type' => 'ticket_purchase_earnings', 
            'proof_url' => 'earnings_debit', 
            'created_at' => current_time('mysql')
        ]);
        $txn_id = $wpdb->insert_id;
        
        if ($raffle_id > 0 && !empty($numbers_str)) {
            $numbers = explode(',', $numbers_str);
            foreach ($numbers as $num) {
                $num = intval(trim($num));
                if ($num > 0) $wpdb->insert($table_entries, ['user_id' => $user_id, 'raffle_id' => $raffle_id, 'ticket_number' => $num, 'txn_id' => $txn_id, 'created_at' => current_time('mysql')]);
            }
        }
        return ['success' => true, 'message' => 'Success', 'new_balance' => $current_earn - $amount];
    }
    
    // *** CASE 3: BANK TRANSFER (AI-POWERED WITH FAIL-SAFE) ***
    $file = $params['proof'];
    if (!$file) return new WP_Error('missing_proof', 'Missing proof', ['status' => 400]);
    $image_data = file_get_contents($file['tmp_name']);
    $mime_type = $file['type'];
    if (empty($image_data)) return new WP_Error('upload_error', 'Failed to read image content.', ['status' => 400]);

    require_once(ABSPATH . 'wp-admin/includes/image.php'); 
    require_once(ABSPATH . 'wp-admin/includes/file.php'); 
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    $attachment_id = media_handle_sideload($file, 0);
    $proof_url = is_wp_error($attachment_id) ? '' : wp_get_attachment_url($attachment_id);
    $base64_image = base64_encode($image_data);
    
    // --- START FAIL-SAFE AI LOGIC ---
    // Default Variables (The "Sad Path")
    $status = 'manual_review';
    $msg = 'Receipt uploaded. Awaiting final confirmation (usually 5-10 mins).';
    $ai_notes = "System: Pending manual verification."; 
    $extracted_amount = 0; 
    $extracted_txn_id = '';
    $is_success = false;

    // Prepare Request
    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . RK_GEMINI_KEY;
    $admin_acct_number = get_option('rk_account_number', '');
    $prompt_text = "Analyze this receipt image. Target Amount: " . $amount . " Target Account Number: " . $admin_acct_number . " Task: 1. Check if the image contains a transaction for the Target Amount. 2. Check if the image contains the Target Account Number. 3. Extract any Transaction ID. Return JSON: { \"amount_match\": boolean, \"account_match\": boolean, \"txn_id\": \"string_or_null\" }";
    $payload = ["contents" => [["parts" => [["text" => $prompt_text], ["inline_data" => ["mime_type" => $mime_type, "data" => $base64_image]]]]]];

    // Execute Request
    try {
        $response = wp_remote_post($api_url, [
            'body'    => json_encode($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15 // Key Safety Feature: Timeout after 15s to prevent hang
        ]);

        if (is_wp_error($response)) {
            // Network Failure (DNS, Connection Refused)
            $ai_notes .= " AI Connect Error: " . $response->get_error_message();
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($code !== 200) {
                // API Failure (Quota, Server Error)
                $ai_notes .= " AI HTTP Error ($code)";
            } else {
                // Success Path
                $api_response = json_decode($body, true);
                $model_text = isset($api_response['candidates'][0]['content']['parts'][0]['text']) ? $api_response['candidates'][0]['content']['parts'][0]['text'] : '';
                
                // Clean and Parse JSON
                $clean_text = preg_replace('/```json\s*|\s*```/', '', $model_text);
                preg_match('/\{.*\}/s', $clean_text, $matches);
                $json = isset($matches[0]) ? json_decode($matches[0], true) : null;
                
                if ($json) {
                    $extracted_txn_id = isset($json['txn_id']) ? sanitize_text_field($json['txn_id']) : '';
                    $check_amount = isset($json['amount_match']) && $json['amount_match'] === true;
                    $check_account = isset($json['account_match']) && $json['account_match'] === true;
                    
                    // Duplicate Check
                    $check_unique = true;
                    if (!empty($extracted_txn_id)) {
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_txn WHERE txn_ref = %s", $extracted_txn_id));
                        if ($exists > 0) { $check_unique = false; $ai_notes .= " [Duplicate ID: $extracted_txn_id]"; }
                    } else { 
                        // If no ID found, we cannot guarantee uniqueness automatically -> Manual Review
                        $check_unique = false; 
                        $ai_notes .= " [No Txn ID Found]"; 
                    }

                    if ($check_amount && $check_account && $check_unique) {
                        // THE HAPPY PATH: All checks passed
                        $status = 'verified_final'; 
                        $is_success = true; 
                        $msg = 'Payment Verified Automatically!'; 
                        $extracted_amount = $amount;
                        $ai_notes = "Verified by AI. ID: $extracted_txn_id";
                    } else { 
                        // Soft Failure: Logic didn't pass (e.g. amount mismatch)
                        $ai_notes .= " AI Logic Fail: " . json_encode($json); 
                    }
                } else {
                    $ai_notes .= " AI Parse Error: Could not parse JSON.";
                }
            }
        }
    } catch (Exception $e) {
        // Critical System Error (e.g. Memory)
        $ai_notes .= " System Exception: " . $e->getMessage();
    }
    // --- END FAIL-SAFE AI LOGIC ---

    // DB Persistence (Happens regardless of AI outcome)
    $wpdb->insert($table_txn, [
        'user_id' => $user_id, 
        'claimed_amount' => $amount, 
        'gemini_amount' => $extracted_amount, 
        'proof_url' => $proof_url, 
        'status' => $status, 
        'type' => $type, 
        'txn_ref' => $extracted_txn_id, 
        'order_id' => $order_id, 
        'created_at' => current_time('mysql')
    ]);
    $txn_id = $wpdb->insert_id;

    // *** TRIGGER ADMIN NOTIFICATION EMAIL ***
    rk_send_deposit_alert($user_id, $amount, $status, $txn_id);
    
    // *** NEW: TRIGGER TELEGRAM NOTIFICATION ***
    if (function_exists('rk_send_telegram_alert')) {
        $txn_status_emoji = $status === 'verified_final' ? '✅' : '⚠️';
        $telegram_msg = "<b>$txn_status_emoji New Deposit Alert</b>\n" .
                        "👤 <b>User:</b> " . get_userdata($user_id)->display_name . "\n" .
                        "💵 <b>Amount:</b> ₦" . number_format($amount) . "\n" .
                        "🆔 <b>Txn ID:</b> #$txn_id\n" .
                        "📊 <b>Status:</b> " . strtoupper($status) . "\n" .
                        "🔗 <a href='$proof_url'>View Receipt Proof</a>";
        rk_send_telegram_alert($telegram_msg);
    }

    if (!empty($ai_notes)) update_user_meta($user_id, 'last_txn_ai_notes', $ai_notes);
    
    if ($raffle_id > 0 && !empty($numbers_str)) {
        $numbers = explode(',', $numbers_str);
        foreach ($numbers as $num) {
            $num = intval(trim($num));
            if ($num > 0) $wpdb->insert($table_entries, ['user_id' => $user_id, 'raffle_id' => $raffle_id, 'ticket_number' => $num, 'txn_id' => $txn_id, 'created_at' => current_time('mysql')]);
        }
    }

    if ($is_success) {
        if(function_exists('rk_process_referral_commission')) rk_process_referral_commission($user_id, $amount);

        // *** CASHBACK BONUS LOGIC (30%) ***
        // Applied to: 'wallet_deposit' (Top-up) AND 'ticket_purchase' (Checkout Bank Transfer)
        if (in_array($type, ['wallet_deposit', 'ticket_purchase'])) {
            $bonus_percent = defined('RK_DEPOSIT_BONUS_PERCENT') ? RK_DEPOSIT_BONUS_PERCENT : 0;
            
            if ($bonus_percent > 0) {
                $bonus_amount = $amount * $bonus_percent;
                
                // Credit Earnings
                $current_earnings = (float) get_user_meta($user_id, 'earnings_balance', true) ?: 0;
                update_user_meta($user_id, 'earnings_balance', $current_earnings + $bonus_amount);

                // Log Bonus Transaction
                $wpdb->insert($table_txn, [
                    'user_id' => $user_id,
                    'claimed_amount' => $bonus_amount,
                    'status' => 'verified_final',
                    'type' => 'deposit_bonus',
                    'proof_url' => 'system_reward',
                    'order_id' => 'Bonus for Txn #' . $txn_id, // Link to original txn
                    'created_at' => current_time('mysql')
                ]);
            }
        }
        // *** END BONUS LOGIC ***

        if ($type === 'wallet_deposit') { 
            $old = get_user_meta($user_id, 'wallet_balance', true) ?: 0;
            update_user_meta($user_id, 'wallet_balance', $old + $amount); 
            return ['success'=>true, 'message'=>$msg, 'new_balance'=>$old + $amount];
        } else { return ['success'=>true, 'message'=>$msg]; }
    } else { return ['success'=>true, 'status'=>'manual_review', 'message'=>$msg]; }
}

function rk_handle_transfer($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    // Check status if function exists
    if (function_exists('rk_check_user_status')) {
        $status = rk_check_user_status($user_id, 'transfer');
        if (is_wp_error($status)) return $status;
    }

    $amount = floatval($request->get_param('amount'));
    if ($amount <= 0) return new WP_Error('invalid_amount', 'Amount > 0 required', ['status' => 400]);
    $earnings = (float) get_user_meta($user_id, 'earnings_balance', true) ?: 0;
    $wallet = (float) get_user_meta($user_id, 'wallet_balance', true) ?: 0;
    if ($earnings < $amount) return new WP_Error('insufficient', 'Insufficient earnings', ['status' => 400]);
    update_user_meta($user_id, 'earnings_balance', $earnings - $amount);
    update_user_meta($user_id, 'wallet_balance', $wallet + $amount);
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'raffle_transactions', ['user_id' => $user_id, 'claimed_amount' => $amount, 'status' => 'verified_final', 'type' => 'earnings_transfer', 'proof_url' => 'internal_transfer', 'created_at' => current_time('mysql')]);
    return ['success' => true, 'message' => 'Transfer Successful', 'new_wallet' => $wallet + $amount, 'new_earnings' => $earnings - $amount];
}

function rk_handle_withdrawal($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    if (function_exists('rk_check_user_status')) {
        $status = rk_check_user_status($user_id, 'withdraw');
        if (is_wp_error($status)) return $status;
    }

    $params = $request->get_json_params();
    $amount = floatval($params['amount']);
    $account_id = sanitize_text_field($params['account_id']);
    if ($amount < 2000) return new WP_Error('min_limit', 'Minimum withdrawal is ₦2,000', ['status' => 400]);
    $earnings = (float) get_user_meta($user_id, 'earnings_balance', true) ?: 0;
    if ($earnings < $amount) return new WP_Error('insufficient_earnings', 'Insufficient earnings', ['status' => 400]);
    $new_earnings = $earnings - $amount;
    update_user_meta($user_id, 'earnings_balance', $new_earnings);
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'raffle_transactions', ['user_id' => $user_id, 'claimed_amount' => $amount, 'status' => 'pending', 'type' => 'withdrawal', 'proof_url' => 'bank_transfer_req', 'txn_ref' => $account_id, 'created_at' => current_time('mysql')]);
    return ['success' => true, 'message' => 'Withdrawal Request Submitted', 'new_earnings' => $new_earnings];
}

function rk_get_user_transactions($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_transactions';
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT 50", $user_id));
    return $results;
}

function rk_handle_cart_sync($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    $params = $request->get_json_params();
    $cart_data = isset($params['cart']) ? json_encode($params['cart']) : '[]';
    $total_value = isset($params['total']) ? floatval($params['total']) : 0;
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_cart_sessions';
    $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d", $user_id));
    if ($existing) $wpdb->update($table, ['cart_data' => $cart_data, 'total_value' => $total_value, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);
    else $wpdb->insert($table, ['user_id' => $user_id, 'cart_data' => $cart_data, 'total_value' => $total_value, 'updated_at' => current_time('mysql')]);
    return ['success' => true];
}

function rk_get_user_tickets($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    global $wpdb;
    
    $entries_table = $wpdb->prefix . 'raffle_entries';
    if($wpdb->get_var("SHOW TABLES LIKE '$entries_table'") != $entries_table) return []; 

    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $entries_table WHERE user_id = %d ORDER BY created_at DESC", $user_id));
    $grouped = [];
    foreach($results as $row) {
        $rid = $row->raffle_id;
        if (!isset($grouped[$rid])) {
            $expiry_date = get_post_meta($rid, 'expiry_date', true);
            $is_sold_out = get_post_meta($rid, 'is_sold_out', true);
            $status = 'Active';
            if ($is_sold_out) $status = 'Concluded';
            elseif ($expiry_date && time() > (strtotime($expiry_date) + 86400)) $status = 'Expired';
            
            $title = get_the_title($rid);
            if(!$title) $title = 'Raffle #' . $rid;

            $grouped[$rid] = ['raffle_id' => $rid, 'raffle_title' => $title, 'date' => $row->created_at, 'status' => $status, 'tickets' => []];
        }
        $grouped[$rid]['tickets'][] = str_pad($row->ticket_number, 3, '0', STR_PAD_LEFT);
    }
    return array_values($grouped);
}

function rk_handle_daily_claim($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    $rewards = [50, 70, 100, 150, 200, 300, 1000];
    
    // FETCH REAL DB STATE
    $last_claim = get_user_meta($user_id, 'rk_last_claim_date', true);
    $streak = (int) get_user_meta($user_id, 'rk_streak_count', true);
    $points = (int) get_user_meta($user_id, 'rk_points', true);
    
    if ($streak === 0) $streak = 1;
    
    // Time Logic
    $now = current_time('timestamp');
    $today_midnight = strtotime('today', $now);
    $yesterday_midnight = strtotime('yesterday', $now);
    
    if ($last_claim) {
        $last_claim_ts = strtotime($last_claim);
        
        // Block consecutive claims same day
        if ($last_claim_ts >= $today_midnight) {
            return new WP_Error('already_claimed', 'Already claimed today', ['status' => 400]);
        }
        
        // Streak Logic
        if ($last_claim_ts < $yesterday_midnight) { 
            // Missed a day -> Reset
            $streak = 1; 
        } else { 
            // Consecutve day -> Increment
            $streak++; 
            if ($streak > 7) $streak = 1; // Cycle reset
        }
    } else { 
        $streak = 1; 
    }
    
    // Reward based on NEW streak (1-based index)
    // Array is 0-based
    $reward_amount = $rewards[$streak - 1];
    
    update_user_meta($user_id, 'rk_points', $points + $reward_amount);
    update_user_meta($user_id, 'rk_streak_count', $streak);
    update_user_meta($user_id, 'rk_last_claim_date', current_time('mysql'));
    
    return [
        'success' => true, 
        'points_added' => $reward_amount, 
        'new_total_points' => $points + $reward_amount, 
        'new_streak' => $streak
    ];
}

function rk_get_rewards_state($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    $points = (int) get_user_meta($user_id, 'rk_points', true);
    $db_streak = (int) get_user_meta($user_id, 'rk_streak_count', true);
    $last_claim = get_user_meta($user_id, 'rk_last_claim_date', true);
    $completed_tasks = get_user_meta($user_id, 'rk_completed_tasks', true) ?: [];
    
    // Referral Data
    $user_info = get_userdata($user_id);
    $frontend_base = defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : 'https://rafflekings.com.ng';
    $referral_link = $frontend_base . '/?ref=' . $user_info->user_login;
    $referral_count = (int) get_user_meta($user_id, 'rk_referral_count', true);
    
    // --- ROBUST VISUAL STREAK LOGIC ---
    // The DB stores the *last completed* streak.
    // The Frontend needs to know the *current active* target.
    
    if ($db_streak === 0) $db_streak = 1;

    $now = current_time('timestamp');
    $today_midnight = strtotime('today', $now);
    $yesterday_midnight = strtotime('yesterday', $now);
    
    $is_claimed_today = false;
    $visual_streak = $db_streak;

    if ($last_claim) {
        $last_claim_ts = strtotime($last_claim);
        
        if ($last_claim_ts >= $today_midnight) {
            // Case A: Claimed Today
            $is_claimed_today = true;
            $visual_streak = $db_streak; // Show current level as completed (checkmarked)
        } elseif ($last_claim_ts < $yesterday_midnight) {
            // Case B: Streak Broken
            $visual_streak = 1; // Reset visual to Day 1
        } else {
            // Case C: Consecutive Day (Unclaimed)
            // We need to show the NEXT step.
            // If DB says 2, we are working on 3.
            $visual_streak = $db_streak + 1;
            
            // Handle the 7 -> 1 Cycle Loop PREDICTION
            if ($visual_streak > 7) $visual_streak = 1;
        }
    } else {
        // Never claimed
        $visual_streak = 1;
    }

    return [
        'points' => $points, 
        'streak' => $visual_streak, // Send the Visual Target
        'db_streak' => $db_streak,
        'is_claimed_today' => $is_claimed_today, 
        'last_claim' => $last_claim, 
        'completed_tasks' => $completed_tasks, 
        'referral_link' => $referral_link, 
        'referral_count' => $referral_count,
        'server_time' => current_time('c') // ISO 8601 for sync
    ];
}

function rk_handle_task_claim($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    $params = $request->get_json_params();
    $task_id = sanitize_text_field($params['task_id']);
    $task_rewards = ['push_notification' => 1500, 'join_community' => 1300, 'whatsapp_follow' => 800, 'whatsapp_share' => 500];
    if (!array_key_exists($task_id, $task_rewards)) return new WP_Error('invalid_task', 'Unknown Task', ['status' => 400]);
    $completed = get_user_meta($user_id, 'rk_completed_tasks', true) ?: [];
    if ($task_id === 'whatsapp_share') {
        $last_share = get_user_meta($user_id, 'rk_last_share_date', true);
        if ($last_share && strtotime($last_share) >= strtotime('today')) return new WP_Error('daily_limit', 'Come back tomorrow to share again', ['status' => 400]);
        update_user_meta($user_id, 'rk_last_share_date', current_time('mysql'));
    } else {
        if (in_array($task_id, $completed)) return new WP_Error('already_completed', 'Task already completed', ['status' => 400]);
        $completed[] = $task_id;
        update_user_meta($user_id, 'rk_completed_tasks', $completed);
    }
    $points = (int) get_user_meta($user_id, 'rk_points', true);
    $reward = $task_rewards[$task_id];
    update_user_meta($user_id, 'rk_points', $points + $reward);
    return ['success' => true, 'points_added' => $reward, 'new_total' => $points + $reward];
}

function rk_get_referral_stats($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    $click_count = (int) get_user_meta($user_id, 'rk_referral_clicks', true);
    $signup_count = (int) get_user_meta($user_id, 'rk_referral_count', true); 
    $total_earned = (float) get_user_meta($user_id, 'rk_referral_earnings_total', true);

    global $wpdb;
    $table = $wpdb->prefix . 'raffle_transactions';
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE user_id = %d AND type = 'referral_commission' ORDER BY created_at DESC LIMIT 20", 
        $user_id
    ));

    $history = [];
    foreach($logs as $log) {
        $history[] = [
            'user' => str_replace('From: ', '', $log->order_id), 
            'date' => human_time_diff(strtotime($log->created_at), current_time('timestamp')) . ' ago',
            'status' => 'verified',
            'amount' => $log->claimed_amount
        ];
    }

    $pending_users = get_users([
        'meta_key' => 'referred_by',
        'meta_value' => $user_id,
        'number' => 5,
        'orderby' => 'registered',
        'order' => 'DESC'
    ]);

    foreach($pending_users as $pu) {
        $is_paid = get_user_meta($pu->ID, 'referral_commission_paid', true);
        if (!$is_paid) {
            array_unshift($history, [
                'user' => $pu->display_name,
                'date' => 'Registered',
                'status' => 'pending',
                'amount' => 0
            ]);
        }
    }

    return [
        'clicks' => $click_count, 
        'signups' => $signup_count,
        'earnings' => $total_earned,
        'history' => array_slice($history, 0, 20)
    ];
}

function rk_post_live_comment($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Please login to chat', ['status' => 401]);

    $params = $request->get_json_params();
    $msg = sanitize_text_field($params['message']);
    
    if (empty($msg)) return new WP_Error('empty', 'Message empty', ['status' => 400]);

    global $wpdb;
    $table = $wpdb->prefix . 'raffle_live_comments';
    $user = get_userdata($user_id);
    
    $wpdb->insert($table, [
        'user_id' => $user_id,
        'user_name' => $user->display_name,
        'message' => $msg
    ]);

    return ['success' => true];
}

function rk_get_live_comments($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_live_comments';
    if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) return [];
    $results = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 20");
    return array_reverse($results);
}

function rk_get_hall_of_fame_data() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_winners';
    
    if($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
        return ['featured' => [], 'recent' => [], 'total_count' => 0];
    }
    
    $featured = $wpdb->get_results("
        SELECT w.*, u.display_name 
        FROM $table w
        JOIN {$wpdb->users} u ON w.user_id = u.ID
        WHERE w.is_featured = 1 AND w.is_visible = 1
        ORDER BY w.won_at DESC LIMIT 5
    ");
    
    $recent = $wpdb->get_results("
        SELECT w.*, u.display_name 
        FROM $table w
        JOIN {$wpdb->users} u ON w.user_id = u.ID
        WHERE w.is_visible = 1 AND w.is_featured = 0
        ORDER BY w.won_at DESC LIMIT 50
    ");
    
    $format_winner = function($row) {
        $avatar = get_user_meta($row->user_id, 'profile_pic_url', true);
        $state = get_user_meta($row->user_id, 'state_of_residence', true) ?: 'Nigeria';
        
        return [
            'user_id' => $row->user_id,
            'name' => $row->display_name,
            'avatar' => $avatar, 
            'state' => $state,
            'prize' => $row->prize_name,
            'ticket' => $row->ticket_number,
            'time_ago' => human_time_diff(strtotime($row->won_at), current_time('timestamp')) . ' ago'
        ];
    };

    return [
        'featured' => array_map($format_winner, $featured),
        'recent' => array_map($format_winner, $recent),
        'total_count' => count($featured) + count($recent)
    ];
}

// *** NEW: GET TUTORIALS ***
function rk_get_tutorials($request) {
    $args = [
        'post_type' => 'tutorial',
        'posts_per_page' => 20,
        'orderby' => 'date',
        'order' => 'DESC'
    ];
    $posts = get_posts($args);
    $data = [];
    $featured = null;

    foreach ($posts as $post) {
        $meta = get_post_meta($post->ID);
        $is_featured = get_post_meta($post->ID, 'is_featured', true);
        
        $item = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => get_the_excerpt($post->ID),
            'content' => apply_filters('the_content', $post->post_content),
            'thumbnail' => get_the_post_thumbnail_url($post->ID, 'large'),
            'author' => get_the_author_meta('display_name', $post->post_author),
            'date_ago' => human_time_diff(strtotime($post->post_date), current_time('timestamp')) . ' ago',
            'meta' => [
                'video_url' => get_post_meta($post->ID, 'video_url', true),
                'category' => get_post_meta($post->ID, 'category_badge', true) ?: 'Guide',
                'read_time' => get_post_meta($post->ID, 'read_time', true) ?: '3 min',
                'helpful_count' => (int) get_post_meta($post->ID, 'helpful_count', true)
            ]
        ];

        if ($is_featured && !$featured) {
            $featured = $item;
        } else {
            $data[] = $item;
        }
    }

    return ['featured' => $featured, 'list' => $data];
}

// *** NEW: HANDLE HELPFUL ***
function rk_tutorial_mark_helpful($request) {
    $params = $request->get_json_params();
    $post_id = intval($params['id']);
    
    if (!$post_id) return new WP_Error('invalid_id', 'ID required', ['status' => 400]);

    $current = (int) get_post_meta($post_id, 'helpful_count', true);
    $new_count = $current + 1;
    
    update_post_meta($post_id, 'helpful_count', $new_count);
    
    return ['success' => true, 'new_count' => $new_count];
}

// ==========================================
// *** NEW: DEPOSIT NOTIFICATION SYSTEM ***
// ==========================================

function rk_send_deposit_alert($user_id, $amount, $status, $txn_id) {
    // 1. Get the email from settings (or default to WP Admin email)
    $to = get_option('rk_notification_email', get_option('admin_email'));
    
    // 2. Get User Info
    $user = get_userdata($user_id);
    $user_name = $user ? $user->display_name : 'Unknown User';
    $user_login = $user ? $user->user_login : '-';

    // 3. Subject Line
    $subject = "💰 New Deposit: ₦" . number_format($amount) . " (" . strtoupper($status) . ")";

    // 4. Email Body (HTML)
    $message = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px; background-color: #f9f9f9;'>
        <h2 style='color: #4f46e5; margin-top: 0; border-bottom: 2px solid #4f46e5; padding-bottom: 10px;'>New Deposit Received</h2>
        
        <p style='font-size: 16px; color: #333;'>A new deposit request has been logged in the system.</p>
        
        <table style='width: 100%; border-collapse: collapse; background: #fff; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>
            <tr>
                <td style='padding: 12px; border-bottom: 1px solid #eee; background: #f4f4f5; width: 30%;'><strong>User</strong></td>
                <td style='padding: 12px; border-bottom: 1px solid #eee;'>$user_name ($user_login)</td>
            </tr>
            <tr>
                <td style='padding: 12px; border-bottom: 1px solid #eee; background: #f4f4f5;'><strong>Amount</strong></td>
                <td style='padding: 12px; border-bottom: 1px solid #eee; font-size: 1.2em; font-weight: bold; color: #16a34a;'>₦" . number_format($amount) . "</td>
            </tr>
            <tr>
                <td style='padding: 12px; border-bottom: 1px solid #eee; background: #f4f4f5;'><strong>Status</strong></td>
                <td style='padding: 12px; border-bottom: 1px solid #eee;'>
                    <span style='background: #e0e7ff; color: #4338ca; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.9em;'>" . strtoupper(str_replace('_', ' ', $status)) . "</span>
                </td>
            </tr>
            <tr>
                <td style='padding: 12px; background: #f4f4f5;'><strong>Txn ID</strong></td>
                <td style='padding: 12px;'>#$txn_id</td>
            </tr>
        </table>

        <div style='margin-top: 25px; text-align: center;'>
            <a href='" . admin_url('admin.php?page=raffle-transactions') . "' style='background-color: #4f46e5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>View Transaction in Admin</a>
        </div>
        
        <p style='margin-top: 30px; font-size: 12px; color: #999; text-align: center;'>Sent from RaffleKings System</p>
    </div>
    ";

    // 5. Send
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail($to, $subject, $message, $headers);
}

// ==========================================
// *** NEW: TELEGRAM NOTIFICATION SYSTEM ***
// ==========================================

function rk_send_telegram_alert($message) {
    // Check if constants are defined in theme-config.php
    $token = defined('RK_TELEGRAM_BOT_TOKEN') ? RK_TELEGRAM_BOT_TOKEN : '';
    $chat_ids = defined('RK_TELEGRAM_ADMIN_IDS') ? RK_TELEGRAM_ADMIN_IDS : '';

    if (empty($token) || empty($chat_ids)) {
        return; // Silent fail if not configured
    }

    $ids = explode(',', $chat_ids);

    foreach ($ids as $chat_id) {
        $url = "https://api.telegram.org/bot$token/sendMessage";
        
        $response = wp_remote_post($url, [
            'body' => [
                'chat_id' => trim($chat_id),
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false
            ]
        ]);
        
        // Optional logging for debug (comment out in production)
        // if (is_wp_error($response)) { error_log('Telegram Error: ' . $response->get_error_message()); }
    }
}