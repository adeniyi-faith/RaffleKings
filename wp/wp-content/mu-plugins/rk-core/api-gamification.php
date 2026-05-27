<?php
/**
 * Module: Gamification, Raffles & Engagement
 * Handles Raffles, Draws, Spin & Win, Tutorials, and Live Chat.
 */

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
        return $amtB <=> $amtA; // ✅ FIX APPLIED: Added return statement
    });

    return new WP_REST_Response([
        'featured' => array_slice($formatted, 0, 5), // Top 5 by amount
        'recent' => array_slice($formatted, 5),      // The rest
        'total_count' => count($formatted)
    ], 200);
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

// *** UPDATED: UNLIMITED SPINS LOGIC (SPLIT TRANSACTION) ***
// SECURITY FIX: Added Database Locking to prevent Double-Spend
function rk_execute_spin_logic($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    // BAN CHECK
    if (is_wp_error($status = rk_check_user_status($user_id, 'spin'))) return $status;

    global $wpdb;
    
    // 0. ACQUIRE LOCK (5 second timeout)
    $lock_name = "spin_user_{$user_id}";
    $lock_acquired = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock_name));

    if (!$lock_acquired) {
        return new WP_Error('concurrent_spin', 'Please wait for previous spin to complete', ['status' => 429]);
    }

    try {
        $cost = 50; // Cost is 50 Points
        $current_points = (int) get_user_meta($user_id, 'rk_points', true);
        
        // 1. Validate Balance
        if ($current_points < $cost) {
            return new WP_Error('insufficient_points', 'You need 50 points to spin.', ['status' => 400]);
        }

        // ---------------------------------------------------------
        // STEP 1: INSTANT DEDUCTION (Separate Transaction)
        // ---------------------------------------------------------
        $balance_after_deduction = $current_points - $cost;
        update_user_meta($user_id, 'rk_points', $balance_after_deduction);

        // Log the Deduction explicitly
        $table_points = $wpdb->prefix . 'raffle_point_logs';
        if($wpdb->get_var("SHOW TABLES LIKE '$table_points'") == $table_points) {
            $wpdb->insert($table_points, [
                'user_id' => $user_id,
                'activity_type' => 'spin_cost',
                'points_amount' => -$cost,
                'description' => "Spin Entry Fee",
                'balance_after' => $balance_after_deduction
            ]);
        }

        // ---------------------------------------------------------
        // STEP 2: DETERMINE PRIZE
        // ---------------------------------------------------------
        $prizes = [
            [15,    600, 'loss'],   // 0: 60% Chance -> Pays 15
            [50,    300, 'tie'],    // 1: 30% Chance -> Pays 50
            [150,   80,  'win'],    // 2: 8% Chance  -> Pays 150
            [500,   20,  'jackpot'] // 3: 2% Chance  -> Pays 500
        ];

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

        // ---------------------------------------------------------
        // STEP 3: CREDIT PRIZE (Separate Transaction)
        // ---------------------------------------------------------
        $final_balance = $balance_after_deduction + $won_points;

        if ($won_points > 0) {
            update_user_meta($user_id, 'rk_points', $final_balance);

            // Log the Prize explicitly
            if($wpdb->get_var("SHOW TABLES LIKE '$table_points'") == $table_points) {
                $wpdb->insert($table_points, [
                    'user_id' => $user_id,
                    'activity_type' => 'spin_win',
                    'points_amount' => $won_points,
                    'description' => "Spin Prize",
                    'balance_after' => $final_balance
                ]);
            }
        }

        // Return Data
        return [
            'success' => true,
            'payout' => $won_points,
            'old_balance' => $current_points,
            'new_balance' => $final_balance, // Frontend will update to this final amount
            'visual_index' => $won_index,
            'is_unlimited' => true
        ];
    } finally {
        // 6. RELEASE LOCK
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
    }
}

// *** UPDATED: CENTRAL REDEMPTION LOGIC ***
function rk_handle_redeem_points($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);
    
    // BAN CHECK
    if (is_wp_error($status = rk_check_user_status($user_id, 'redeem'))) return $status;

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

    // SECURITY FIX: Check if draw already ran for this raffle
    $existing_winners = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}raffle_winners WHERE raffle_id = %d",
        $raffle_id
    ));
    
    if ($existing_winners > 0) {
        return new WP_Error('draw_exists', 'Draw already completed for this raffle', ['status' => 400]);
    }

    // SECURITY FIX: Audit Log
    $admin_user = wp_get_current_user();
    error_log("DRAW EXECUTED: Raffle #$raffle_id by {$admin_user->user_login} ({$admin_user->ID})");

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
    // *** SAFETY UPDATE: Join with Transactions to ensure only Verified tickets win
    $entries_table = $wpdb->prefix . 'raffle_entries';
    $txn_table = $wpdb->prefix . 'raffle_transactions';
    
    $all_tickets = $wpdb->get_results($wpdb->prepare("
        SELECT e.user_id, e.ticket_number 
        FROM $entries_table e
        JOIN $txn_table t ON e.txn_id = t.id
        WHERE e.raffle_id = %d AND t.status = 'verified_final'
    ", $raffle_id));

    if (empty($all_tickets)) return new WP_Error('no_entries', 'No confirmed tickets sold for this raffle.', ['status' => 400]);

    // 3. Apply Exclusion Rules (Cooldown: 3 Days)
    $winners_table = $wpdb->prefix . 'raffle_winners';
    $cooldown_days = 3; 
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
    
    // 🔥 TEMU INTEGRATION: Trigger Push Notification (Social Proof)
    do_action('rk_draw_completed', $raffle_id, count($winners_generated), $admin_user->user_login);

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

/**
 * UPDATED: Credit Raffle Winner with Email Notification
 */
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
    
    // SECURITY FIX: Use safe balance update with transaction
    $wpdb->query('START TRANSACTION');
    try {
        $current_earnings = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'earnings_balance' FOR UPDATE", 
            $user_id
        ));
        $current_earnings = $current_earnings ? (float)$current_earnings : 0;
        update_user_meta($user_id, 'earnings_balance', $current_earnings + $amount);
        $wpdb->query('COMMIT');
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('db_error', $e->getMessage());
    }

    // Mark as credited in winners table
    $wpdb->update($winners_table, ['is_credited' => 1], ['id' => $win_id]);

    // Log transaction
    $wpdb->insert($wpdb->prefix . 'raffle_transactions', [
        'user_id' => $user_id,
        'claimed_amount' => $amount,
        'status' => 'verified_final',
        'type' => 'prize_win',
        'proof_url' => 'admin_credit',
        'txn_ref' => 'WIN-' . $win_id,
        'created_at' => current_time('mysql')
    ]);

    // ✅ NEW: Trigger winner notification email
    do_action('rk_winner_credited', $user_id, $winner_record->prize_name, $amount);

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
        'total_pool_size' => (int)$total_entries 
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

    // ✅ FIX: Calculate Active Status Logic
    $is_active = true;
    $is_sold_out = get_post_meta($post['id'], 'is_sold_out', true);
    
    if($is_sold_out === '1' || $is_sold_out === true) {
        $is_active = false;
    } elseif($expiry) {
        // Compare expiry date to current date
        $expiry_timestamp = strtotime($expiry);
        if($expiry_timestamp && $expiry_timestamp < time()) {
            $is_active = false;
        }
    }

    return [
        'price' => $price, 
        'tagline' => get_post_meta($post['id'], 'raffle_tagline', true), 
        'grand_prize' => $grand_prize, 
        'prize_list' => array_values($prize_list), 
        'sold' => $sold, 
        'max' => $max, 
        'remaining' => $remaining, 
        'progress' => round($progress), 
        'is_sold_out' => $is_sold_out, 
        'is_active' => $is_active, // ✅ ADDED FIELD
        'expiry' => $expiry, 
        'winner' => get_post_meta($post['id'], 'raffle_winner', true), 
        'taken_numbers' => array_map('intval', $taken_numbers) 
    ];
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

// *** NEW: APPLY RECOVERY DISCOUNT (Golden Box Trigger) ***
function rk_apply_recovery_discount($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    global $wpdb;
    $table_carts = $wpdb->prefix . 'raffle_cart_sessions';
    
    // 1. Get Current Cart
    $cart = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_carts WHERE user_id = %d", $user_id));
    if (!$cart) return new WP_Error('no_cart', 'Cart is empty', ['status' => 400]);

    // 2. Validate Minimum Amount (1000 Naira)
    // Note: We use the stored total_value which should be accurate from the last sync
    $original_total = (float) $cart->total_value;
    
    if ($original_total < 1000) {
        return new WP_Error('low_value', 'Cart value too low for discount', ['status' => 400]);
    }

    // 3. Apply 10% Discount
    $discount_amount = ceil($original_total * 0.10);
    $new_total = $original_total - $discount_amount;

    // 4. Update Cart in DB with Discount Meta
    // We store metadata in the 'cart_data' JSON.
    $cart_data = json_decode($cart->cart_data, true);
    if (!is_array($cart_data)) $cart_data = []; // Safety
    
    $cart_data['is_recovery_discount'] = true;
    $cart_data['discount_amount'] = $discount_amount;
    $cart_data['discount_expiry'] = time() + (25 * 60); // Expires in 25 mins
    
    $wpdb->update($table_carts, [
        'cart_data' => json_encode($cart_data),
        'total_value' => $new_total, // Update total so payment processor sees new price
        'updated_at' => current_time('mysql')
    ], ['user_id' => $user_id]);

    return [
        'success' => true,
        'message' => 'Discount Applied!',
        'original_total' => $original_total,
        'discount_amount' => $discount_amount,
        'new_total' => $new_total,
        'expires_in_seconds' => 1500 // 25 Minutes
    ];
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
    $referral_link = $frontend_base . '/?ref=' . ($user_info ? $user_info->user_login : '');
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
// TRIGGER 5: RAFFLE DRAW COMPLETED
// ==========================================

/**
 * Send Telegram alert + Push Notification when a raffle draw is executed
 */
add_action('rk_draw_completed', 'rk_notify_all_channels', 10, 3);

function rk_notify_all_channels($raffle_id, $winner_count, $admin_username) {
    global $wpdb;
    $raffle_title = get_the_title($raffle_id);
    
    // 1. TELEGRAM ALERT (Admin)
    $message = "🎲 <b>RAFFLE DRAW COMPLETED</b>\n\n" .
               "🎟️ Raffle: <b>$raffle_title</b>\n" .
               "🏆 Winners: <b>$winner_count</b>\n" .
               "👤 Executed by: <b>$admin_username</b>\n\n" .
               "⚠️ <i>Review winners in admin panel</i>\n" .
               "⏰ " . current_time('F j, Y - g:i A');
    
    if (function_exists('rk_send_telegram_alert')) {
        rk_send_telegram_alert($message);
    }

    // 2. TEMU-STYLE PUSH NOTIFICATION (To All Users)
    // Only send if we have a winner (even if hidden)
    if ($winner_count > 0) {
        $winner = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}raffle_winners WHERE raffle_id = $raffle_id ORDER BY won_amount DESC LIMIT 1");
        
        if ($winner) {
            $user = get_userdata($winner->user_id);
            $winner_name = $user ? $user->display_name : 'Someone';
            $amount = number_format($winner->won_amount);

            $template = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}raffle_notification_templates WHERE bucket_type = 'push_winner_alert'");
            
            if ($template) {
                $title = str_replace(['[AMOUNT]'], [$amount], $template->title);
                $body = str_replace(
                    ['[NAME]', '[AMOUNT]', '[RAFFLE_NAME]'], 
                    [$winner_name, $amount, $raffle_title], 
                    $template->body_text
                );

                rk_send_broadcast_push($title, $body);
            }
        }
    }
}

/**
 * Helper: Send Broadcast Push (To All Segments)
 */
function rk_send_broadcast_push($title, $message) {
    $app_id = defined('RK_ONESIGNAL_APP_ID') ? RK_ONESIGNAL_APP_ID : '';
    $api_key = defined('RK_ONESIGNAL_API_KEY') ? RK_ONESIGNAL_API_KEY : '';

    if(empty($app_id) || empty($api_key)) return;

    $fields = array(
        'app_id' => $app_id,
        'included_segments' => array('All'),
        'headings' => array("en" => $title),
        'contents' => array("en" => $message),
        'url' => defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : site_url()
    );
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Basic ' . $api_key
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    
    curl_exec($ch);
    curl_close($ch);
}
?>