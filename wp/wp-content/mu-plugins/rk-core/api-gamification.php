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

    // Phase 3 item 35c — locked (via the points row itself, not a
    // MySQL-specific GET_LOCK()), ledgered spin on the unified points
    // tables (rewards-bridge.php) when the flag is on.
    if (rk_rewards_unified_enabled()) {
        try {
            $result = rk_points_bridge_spin($user_id);
        } catch (Exception $e) {
            return new WP_Error('insufficient_points', 'You need 50 points to spin.', ['status' => 400]);
        }

        return [
            'success' => true,
            'payout' => $result['payout'],
            'new_balance' => $result['new_balance'],
            'visual_index' => $result['visual_index'],
            'is_unlimited' => true,
        ];
    }

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

    // Phase 3 item 35c — locked debit + a real credit into the SAME
    // unified wallet item 33 built, when the flag is on (see
    // rewards-bridge.php's rk_points_bridge_redeem() docblock for why
    // this also requires the wallet flag specifically).
    if (rk_rewards_unified_enabled()) {
        try {
            $result = rk_points_bridge_redeem($user_id);
        } catch (Exception $e) {
            return new WP_Error('low_points', $e->getMessage(), ['status' => 400]);
        }

        return ['success' => true] + $result;
    }

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

    // Phase 3 item 35: while the flag is on, this delegates entirely to
    // the provably-fair engine (draw-bridge.php — a port of
    // App\Services\ProvablyFairDrawService) instead of the plain
    // shuffle() below. Winners land in the SAME wp_raffle_winners table
    // either way. A raffle must be committed first (see the "Commit
    // Seed" admin action / rk_draw_bridge_commit_seed()) — if it hasn't
    // been, this returns a clear error rather than silently running the
    // old engine instead.
    if (rk_draw_engine_unified_enabled()) {
        $admin_user = wp_get_current_user();
        try {
            // The legacy admin UI has one button ("GENERATE WINNERS"),
            // not a separate commit step — commit right before running
            // so the seed is still fixed strictly BEFORE the eligible
            // pool is read a few lines later inside rk_draw_bridge_run_draw()
            // (what actually matters for the security property: the
            // operator can't see the pool and then choose a seed to
            // suit it), while keeping the same one-click flow admins
            // already know. Idempotent either way — a raffle already
            // committed elsewhere (e.g. a future Laravel admin action)
            // is left alone.
            rk_draw_bridge_commit_seed($raffle_id);
            $result = rk_draw_bridge_run_draw($raffle_id);
        } catch (Exception $e) {
            return new WP_Error('draw_failed', $e->getMessage(), ['status' => 400]);
        }

        error_log("DRAW EXECUTED (unified engine): Raffle #$raffle_id by {$admin_user->user_login} ({$admin_user->ID})");
        do_action('rk_draw_completed', $raffle_id, $result['winner_count'], $admin_user->user_login);

        return ['success' => true, 'winner_count' => $result['winner_count'], 'message' => 'Winners generated successfully (Hidden). Go to Winners Manager to approve.'];
    }

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

    // Phase 3 item 35: closes the wallet-vs-usermeta divergence found
    // while researching the draw-engine cutover — see
    // wallet-bridge.php's rk_wallet_credit_winner() docblock for the
    // real double-credit race it also fixes.
    if (rk_wallets_unified_enabled()) {
        try {
            $result = rk_wallet_credit_winner($win_id, $amount);
        } catch (Exception $e) {
            $status = $e->getMessage() === 'Winner record not found' ? 404 : 400;
            return new WP_Error($status === 404 ? 'not_found' : 'paid', $e->getMessage(), ['status' => $status]);
        }

        do_action('rk_winner_credited', $result['user_id'], $result['prize_name'], $amount);

        return ['success' => true, 'message' => 'User Credited ₦' . number_format($amount)];
    }

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

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35d — rk_winner_credited has fired
 * from rk_credit_raffle_winner() (and, since item 35a, from
 * wallet-bridge.php's rk_wallet_credit_winner()) since before this
 * migration started, with NO listener anywhere — the inline comment
 * above it ("Trigger winner notification email") was aspirational; a
 * winner credited through either path got no notification of any kind.
 * This is that listener — a real one, on both email and Telegram, using
 * the same rk_send_email()/rk_send_telegram_alert() helpers every other
 * legacy notification already uses.
 */
add_action('rk_winner_credited', 'rk_notify_winner_credited', 10, 3);

function rk_notify_winner_credited($user_id, $prize_name, $amount) {
    $user = get_userdata($user_id);
    if (!$user) return;

    if (function_exists('rk_send_email')) {
        $subject = "💰 You've been credited ₦" . number_format($amount) . "!";
        $body = "
            <div style='font-family: sans-serif; color: #333;'>
                <h2 style='color: #16a34a;'>Prize Credited!</h2>
                <p>Hi " . esc_html($user->display_name) . ",</p>
                <p>Your prize (<strong>" . esc_html($prize_name) . "</strong>) has been credited: <strong>₦" . number_format($amount) . "</strong> added to your earnings balance.</p>
            </div>
        ";
        rk_send_email($user->user_email, $subject, $body);
    }

    if (function_exists('rk_send_telegram_alert')) {
        rk_send_telegram_alert(
            "💰 <b>WINNER CREDITED</b>\n" .
            "👤 User: " . esc_html($user->display_name) . "\n" .
            "🏆 Prize: " . esc_html($prize_name) . "\n" .
            "💵 Amount: ₦" . number_format($amount)
        );
    }
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
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $entries_table)) !== $entries_table) return []; 

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT raffle_id, ticket_number, created_at FROM $entries_table WHERE user_id = %d ORDER BY created_at DESC",
        $user_id
    ));
    if ($wpdb->last_error) {
        error_log('RaffleKings tickets query failed: ' . $wpdb->last_error);
        return new WP_Error('tickets_unavailable', 'Tickets are temporarily unavailable.', ['status' => 500]);
    }

    $grouped = [];
    foreach((array) $results as $row) {
        $rid = isset($row->raffle_id) ? (int) $row->raffle_id : 0;
        if ($rid <= 0) continue;

        if (!isset($grouped[$rid])) {
            $expiry_date = get_post_meta($rid, 'expiry_date', true);
            $is_sold_out = get_post_meta($rid, 'is_sold_out', true);
            $expiry_ts = $expiry_date ? strtotime($expiry_date) : false;
            $status = 'Active';
            if ($is_sold_out) $status = 'Concluded';
            elseif ($expiry_ts && time() > ($expiry_ts + 86400)) $status = 'Expired';
            
            $title = get_the_title($rid);
            if(!$title) $title = 'Raffle #' . $rid;

            $grouped[$rid] = ['raffle_id' => $rid, 'raffle_title' => $title, 'date' => $row->created_at, 'status' => $status, 'tickets' => []];
        }
        $grouped[$rid]['tickets'][] = str_pad((string) $row->ticket_number, 3, '0', STR_PAD_LEFT);
    }
    return array_values($grouped);
}

function rk_normalize_completed_tasks($completed_tasks) {
    if (is_array($completed_tasks)) {
        return array_values(array_filter(array_map('sanitize_text_field', $completed_tasks)));
    }

    if (is_string($completed_tasks) && $completed_tasks !== '') {
        $maybe_unserialized = maybe_unserialize($completed_tasks);
        if (is_array($maybe_unserialized)) {
            return array_values(array_filter(array_map('sanitize_text_field', $maybe_unserialized)));
        }

        $maybe_json = json_decode($completed_tasks, true);
        if (is_array($maybe_json)) {
            return array_values(array_filter(array_map('sanitize_text_field', $maybe_json)));
        }
    }

    return [];
}

function rk_get_request_params($request) {
    if ($request instanceof WP_REST_Request) {
        $params = $request->get_json_params();
        if (is_array($params) && !empty($params)) {
            return $params;
        }

        $params = $request->get_body_params();
        if (is_array($params) && !empty($params)) {
            return $params;
        }

        $params = $request->get_params();
        return is_array($params) ? $params : [];
    }

    return is_array($request) ? $request : [];
}

function rk_handle_daily_claim($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    // Phase 3 item 35c — locked, ledgered claim on the unified points
    // tables (rewards-bridge.php) when the flag is on.
    if (rk_rewards_unified_enabled()) {
        try {
            $result = rk_points_bridge_daily_claim($user_id);
        } catch (Exception $e) {
            return new WP_Error('already_claimed', $e->getMessage(), ['status' => 400]);
        }

        return ['success' => true] + $result;
    }

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

    // Referral Data (unaffected by the rewards-unification flag — the
    // referral count itself is still legacy-owned data either way).
    $user_info = get_userdata($user_id);
    $frontend_base = defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : 'https://rafflekings.com.ng';
    $referral_link = $frontend_base . '/?ref=' . ($user_info ? $user_info->user_login : '');
    $referral_count = (int) get_user_meta($user_id, 'rk_referral_count', true);

    // Phase 3 item 35c — read from the unified points tables when the
    // flag is on. rk_points_bridge_daily_state() already computes the
    // same "visual streak" prediction the block below derives by hand.
    if (rk_rewards_unified_enabled()) {
        global $wpdb;
        $points = rk_points_read_balance($user_id);
        $row = $wpdb->get_row($wpdb->prepare('SELECT streak_count, last_claim_date FROM user_points WHERE user_id = %d', $user_id), ARRAY_A);
        $db_streak = $row ? (int) $row['streak_count'] : 0;
        $last_claim = $row ? $row['last_claim_date'] : null;
        $daily_state = rk_points_bridge_daily_state($user_id);
        $completed_tasks = array_map(fn ($t) => $t['task_id'], array_filter(rk_points_bridge_task_catalog($user_id), fn ($t) => $t['completed'] && !$t['repeatable']));

        return [
            'points' => $points,
            'streak' => $daily_state['streak'],
            'db_streak' => $db_streak,
            'is_claimed_today' => $daily_state['is_claimed_today'],
            'last_claim' => $last_claim,
            'completed_tasks' => array_values($completed_tasks),
            'referral_link' => $referral_link,
            'referral_count' => $referral_count,
            'server_time' => current_time('c'),
        ];
    }

    $points = (int) get_user_meta($user_id, 'rk_points', true);
    $db_streak = (int) get_user_meta($user_id, 'rk_streak_count', true);
    $last_claim = get_user_meta($user_id, 'rk_last_claim_date', true);
    $completed_tasks = rk_normalize_completed_tasks(get_user_meta($user_id, 'rk_completed_tasks', true));

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
    $params = rk_get_request_params($request);
    $task_id = isset($params['task_id']) ? sanitize_text_field($params['task_id']) : '';

    // Phase 3 item 35c — locked, ledgered claim on the unified tables
    // (rewards-bridge.php) when the flag is on.
    if (rk_rewards_unified_enabled()) {
        try {
            $result = rk_points_bridge_task_claim($user_id, $task_id);
        } catch (Exception $e) {
            $code = str_starts_with($e->getMessage(), 'Unknown task') ? 'invalid_task' : 'already_completed';

            return new WP_Error($code, $e->getMessage(), ['status' => 400]);
        }

        return ['success' => true, 'points_added' => $result['points_added'], 'new_total' => $result['new_total_points']];
    }

    $task_rewards = ['push_notification' => 1500, 'join_community' => 1300, 'whatsapp_follow' => 800, 'whatsapp_share' => 500];
    if (!array_key_exists($task_id, $task_rewards)) return new WP_Error('invalid_task', 'Unknown Task', ['status' => 400]);
    $completed = rk_normalize_completed_tasks(get_user_meta($user_id, 'rk_completed_tasks', true));
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

    global $wpdb;

    // Real click count — referral_clicks is written by
    // rk_track_referral_visit() (referral-tracking.php) every time a
    // visitor's browser is actually seen carrying this user's referral
    // link, de-duplicated per visitor. The old rk_referral_clicks usermeta
    // counter nothing ever incremented is gone.
    $click_count = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM referral_clicks WHERE referrer_user_id = %d',
        $user_id
    ));

    $signup_count = (int) get_user_meta($user_id, 'rk_referral_count', true);

    // Paid/pending and total earned now come from the SAME
    // `referral_commissions` table rk_process_referral_commission() (and
    // its unified-path twin) write to — one authoritative place, instead
    // of the old usermeta flag that was written under one key and read
    // under a different one (TD-33), which made every already-paid
    // referral show up as pending too.
    $paid_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT rc.referee_user_id, rc.commission_amount, rc.created_at, u.display_name
         FROM referral_commissions rc
         LEFT JOIN {$wpdb->users} u ON u.ID = rc.referee_user_id
         WHERE rc.referrer_user_id = %d
         ORDER BY rc.created_at DESC
         LIMIT 20",
        $user_id
    ));

    $total_earned = 0.0;
    $paid_referee_ids = [];
    $history = [];
    foreach ($paid_rows as $row) {
        $total_earned += (float) $row->commission_amount;
        $paid_referee_ids[] = (int) $row->referee_user_id;
        $history[] = [
            'user' => $row->display_name ?: 'A referred user',
            'date' => human_time_diff(strtotime($row->created_at), current_time('timestamp')) . ' ago',
            'status' => 'verified',
            'amount' => (float) $row->commission_amount,
        ];
    }

    $referred_users = get_users([
        'meta_key' => 'referred_by',
        'meta_value' => $user_id,
        'number' => 20,
        'orderby' => 'registered',
        'order' => 'DESC',
    ]);

    foreach ($referred_users as $ru) {
        if (in_array((int) $ru->ID, $paid_referee_ids, true)) {
            continue; // already represented above from referral_commissions
        }
        array_unshift($history, [
            'user' => $ru->display_name,
            'date' => 'Registered',
            'status' => 'pending',
            'amount' => 0,
        ]);
    }

    return [
        'clicks' => $click_count,
        'signups' => $signup_count,
        'earnings' => $total_earned,
        'referral_code' => get_userdata($user_id)->user_login,
        'history' => array_slice($history, 0, 20),
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