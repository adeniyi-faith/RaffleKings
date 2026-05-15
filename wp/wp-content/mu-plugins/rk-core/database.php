<?php
/**
 * Database Tables and Custom Post Types
 * Extracted from functions.php
 */

// 1. REGISTER CUSTOM POST TYPES
add_action('init', 'rk_register_cpts');
function rk_register_cpts() {
    // A. RAFFLES
    register_post_type('raffle', [
        'labels' => [
            'name' => 'Raffles',
            'singular_name' => 'Raffle',
            'add_new' => 'Create New Raffle',
            'add_new_item' => 'Add New Raffle Draw'
        ],
        'public' => true,
        'show_in_rest' => true, 
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'menu_icon' => 'dashicons-tickets-alt',
        'has_archive' => false
    ]);

    register_taxonomy('raffle_category', 'raffle', [
        'labels' => ['name' => 'Raffle Categories'],
        'hierarchical' => true,
        'show_in_rest' => true
    ]);

    // B. TUTORIALS (Dynamic Learning Hub)
    register_post_type('tutorial', [
        'labels' => [
            'name' => 'Tutorials',
            'singular_name' => 'Tutorial',
            'add_new' => 'Add Tutorial',
            'add_new_item' => 'Add New Guide'
        ],
        'public' => true,
        'show_in_rest' => true,
        'show_in_menu' => 'raffle-ops', // Nested under your main dashboard
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'menu_icon' => 'dashicons-welcome-learn-more',
        'has_archive' => false
    ]);
}

// 2. AUTO-REGISTER ACF FIELDS (Tutorials ONLY - Raffle ACF is disabled to prevent conflicts)
if( function_exists('acf_add_local_field_group') ):
add_action('acf/init', function() {
    
    // *** Tutorial Details Group ***
    acf_add_local_field_group(array(
        'key' => 'group_tutorial_meta',
        'title' => 'Tutorial Settings',
        'fields' => array(
            array('key' => 'field_is_featured', 'label' => 'Is Featured Video?', 'name' => 'is_featured', 'type' => 'true_false', 'ui' => 1),
            array('key' => 'field_video_url', 'label' => 'Video URL (Optional)', 'name' => 'video_url', 'type' => 'url', 'placeholder' => 'https://youtube.com/...'),
            array('key' => 'field_category_badge', 'label' => 'Category Badge', 'name' => 'category_badge', 'type' => 'text', 'default_value' => 'Strategy'),
            array('key' => 'field_read_time', 'label' => 'Read Time', 'name' => 'read_time', 'type' => 'text', 'default_value' => '3 min read'),
            array('key' => 'field_helpful_count', 'label' => 'Helpful Count (Admin Override)', 'name' => 'helpful_count', 'type' => 'number', 'default_value' => 0, 'instructions' => 'Set a starting number. User clicks will add to this.'),
        ),
        'location' => array(array(array('param' => 'post_type', 'operator' => '==', 'value' => 'tutorial'))),
    ));

});
endif;

// 3. DATABASE TABLES
add_action('init', 'rk_create_db_table');
function rk_create_db_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Table 1: Transactions
    $table_txns = $wpdb->prefix . 'raffle_transactions';
    $sql_txns = "CREATE TABLE $table_txns (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) NOT NULL,
        claimed_amount decimal(10,2) NOT NULL,
        gemini_amount decimal(10,2),
        txn_ref varchar(100),
        order_id varchar(50),
        proof_url varchar(255),
        status varchar(50) DEFAULT 'pending',
        type varchar(50),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_txns);

    // Table 2: Raffle Entries (Tickets)
    $table_entries = $wpdb->prefix . 'raffle_entries';
    $sql_entries = "CREATE TABLE $table_entries (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) NOT NULL,
        raffle_id mediumint(9) NOT NULL,
        ticket_number mediumint(9) NOT NULL,
        txn_id mediumint(9) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY raffle_ticket (raffle_id, ticket_number)
    ) $charset_collate;";
    dbDelta($sql_entries);
    
    // Table 3: Cart Sessions
    $table_carts = $wpdb->prefix . 'raffle_cart_sessions';
    $sql_carts = "CREATE TABLE $table_carts (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) NOT NULL,
        cart_data longtext,
        total_value decimal(10,2) NOT NULL DEFAULT 0,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_cart (user_id)
    ) $charset_collate;";
    dbDelta($sql_carts);

    // Table 4: Raffle Winners
    $table_winners = $wpdb->prefix . 'raffle_winners';
    $sql_winners = "CREATE TABLE $table_winners (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        raffle_id mediumint(9) NOT NULL,
        user_id mediumint(9) NOT NULL,
        ticket_number mediumint(9) NOT NULL,
        prize_name varchar(255) NOT NULL,
        prize_rank int(9) DEFAULT 99,
        prize_cash_value decimal(10,2) DEFAULT 0,
        is_credited tinyint(1) DEFAULT 0,
        is_featured tinyint(1) DEFAULT 0,
        is_visible tinyint(1) DEFAULT 0, 
        won_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_wins (user_id)
    ) $charset_collate;";
    dbDelta($sql_winners);

    // Table 5: Live Comments
    $table_comments = $wpdb->prefix . 'raffle_live_comments';
    $sql_comments = "CREATE TABLE $table_comments (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) NOT NULL,
        user_name varchar(100) NOT NULL,
        message text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_comments);

    // Table 6: Notification Templates
    $table_notifs = $wpdb->prefix . 'raffle_notification_templates';
    $sql_notifs = "CREATE TABLE $table_notifs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        bucket_type varchar(50) NOT NULL,
        title varchar(255) NOT NULL,
        body_text text NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY bucket_idx (bucket_type)
    ) $charset_collate;";
    dbDelta($sql_notifs);

     // Table 7: System Pulse (Error Logs)
    $table_errors = $wpdb->prefix . 'raffle_error_logs';
    $sql_errors = "CREATE TABLE $table_errors (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) DEFAULT 0,
        error_type varchar(50) NOT NULL,
        error_message text NOT NULL,
        source_file varchar(255),
        line_number varchar(20),
        user_agent text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_errors);

    // Table 8: Point Game Logs (Spin & Win)
    $table_points = $wpdb->prefix . 'raffle_point_logs';
    $sql_points = "CREATE TABLE $table_points (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) NOT NULL,
        activity_type varchar(50) NOT NULL,
        points_amount int(11) NOT NULL,
        description varchar(255),
        balance_after int(11) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_activity (user_id)
    ) $charset_collate;";
    dbDelta($sql_points);

    // *** NEW Table 9: Site Notifications (Onsite Alerts) ***
    $table_site_notices = $wpdb->prefix . 'raffle_site_notices';
    $sql_site_notices = "CREATE TABLE $table_site_notices (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        title varchar(100),
        message text NOT NULL,
        type varchar(20) DEFAULT 'info',
        location varchar(20) DEFAULT 'toast_top',
        frequency varchar(20) DEFAULT 'always',
        dismiss_sec int(5) DEFAULT 0,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_site_notices);
    
    // =========================================================
    // SEEDING DEFAULT TEMPLATES (Updated with Temu Push)
    // =========================================================
    
    // 1. Existing Bonus Buckets (Only if table is empty)
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_notifs");
    if ($count == 0) {
        $defaults = [
            ['bucket_e', '🥂 CONGRATULATIONS! You received a Top-Up Bonus!', 'Your Winning Balance is now ₦5,500. You have crossed the limit! Withdraw to your bank immediately to confirm your account status.'],
            ['bucket_c', '😱 SO CLOSE! We added a bonus to your winnings.', 'You are now at ₦[NEW_BALANCE]. You are less than ₦500 away from withdrawing! Play again to cross the line.'],
            ['bucket_a', '🚨 CREDIT ALERT! We added ₦800 to your winnings.', 'You are only ₦200 away from a ticket. Fund wallet to play!'],
            ['bucket_b', '🎟️ FREE TICKET! We just gave you ₦1,000.', 'Convert to spending wallet now to pick your numbers for Friday!'],
            ['bucket_d', '👑 VIP REWARD: You played big today.', 'Here is 5% cash back on us. Check your earnings balance.']
        ];
        foreach ($defaults as $row) {
            $wpdb->insert($table_notifs, ['bucket_type' => $row[0], 'title' => $row[1], 'body_text' => $row[2]]);
        }
    }

    // 2. New Temu-Style Push Templates (Insert if missing)
    $existing_buckets = $wpdb->get_col("SELECT bucket_type FROM $table_notifs");
    
    $push_templates = [
        'push_streak_danger' => [
            'title' => '🔥 STREAK DANGER!',
            'body' => 'You are 1 tap away from losing your Day [STREAK_DAY] Reward. Claim ₦100 now before it resets to ZERO!'
        ],
        'push_points_expiring' => [
            'title' => '⚠️ [POINTS] Points Expiring!',
            'body' => 'URGENT: Your points are pending deletion. Convert them to Cash or Tickets in the next 3 hours.'
        ],
        'push_low_balance' => [
            'title' => '💸 CREDIT ALERT: ₦800 Pending',
            'body' => 'Your wallet is low! We have reserved a 30% Deposit Bonus for you. Tap to claim your ₦800 extra.'
        ],
        'push_inactive_user' => [
            'title' => '💔 We saved this for you...',
            'body' => 'You haven\'t logged in for 7 days. Your 500 Free Points are waiting. Login now to collect them.'
        ],
        'push_winner_alert' => [
            'title' => '⚡ SOMEONE JUST WON ₦[AMOUNT]!',
            'body' => 'LIVE: [NAME] just won ₦[AMOUNT] in the [RAFFLE_NAME]! Tickets are selling fast. You could be next!'
        ]
    ];

    foreach ($push_templates as $key => $data) {
        if (!in_array($key, $existing_buckets)) {
            $wpdb->insert($table_notifs, [
                'bucket_type' => $key,
                'title' => $data['title'],
                'body_text' => $data['body']
            ]);
        }
    }
}

/**
 * 4. NATIVE METABOXES FOR RAFFLE CPT (Replaces ACF)
 */
add_action('add_meta_boxes', 'rk_add_raffle_metaboxes');
function rk_add_raffle_metaboxes() {
    add_meta_box('rk_raffle_meta', 'Raffle Settings', 'rk_raffle_meta_callback', 'raffle', 'normal', 'high');
}

function rk_raffle_meta_callback($post) {
    wp_nonce_field('rk_save_raffle_meta', 'rk_raffle_meta_nonce');
    
    $price = get_post_meta($post->ID, 'price', true);
    $max = get_post_meta($post->ID, 'max', true);
    $sold = get_post_meta($post->ID, 'sold', true);
    $grand_prize = get_post_meta($post->ID, 'grand_prize', true);
    $prize_list = get_post_meta($post->ID, 'prize_list', true);
    $expiry = get_post_meta($post->ID, 'expiry', true);
    $is_sold_out = get_post_meta($post->ID, 'is_sold_out', true);

    echo '<table class="form-table">';
    
    echo '<tr><th><label for="price">Ticket Price (₦)</label></th>';
    echo '<td><input type="number" id="price" name="rk_meta[price]" value="' . esc_attr($price) . '" class="regular-text"></td></tr>';

    echo '<tr><th><label for="max">Max Tickets</label></th>';
    echo '<td><input type="number" id="max" name="rk_meta[max]" value="' . esc_attr($max) . '" class="regular-text"></td></tr>';

    echo '<tr><th><label for="sold">Tickets Sold</label></th>';
    echo '<td><input type="number" id="sold" name="rk_meta[sold]" value="' . esc_attr($sold) . '" class="regular-text"></td></tr>';

    echo '<tr><th><label for="grand_prize">Grand Prize</label></th>';
    echo '<td><input type="text" id="grand_prize" name="rk_meta[grand_prize]" value="' . esc_attr($grand_prize) . '" class="regular-text"></td></tr>';

    echo '<tr><th><label for="prize_list">Secondary Prizes (One per line)</label></th>';
    echo '<td><textarea id="prize_list" name="rk_meta[prize_list]" rows="4" class="large-text">' . esc_textarea($prize_list) . '</textarea><p class="description">Format: Tier Name: Prize Description (e.g., 2nd Prize: ₦50,000)</p></td></tr>';

    echo '<tr><th><label for="expiry">Expiry Date</label></th>';
    echo '<td><input type="date" id="expiry" name="rk_meta[expiry]" value="' . esc_attr($expiry) . '" class="regular-text"></td></tr>';

    echo '<tr><th><label for="is_sold_out">Status</label></th>';
    echo '<td><label><input type="checkbox" id="is_sold_out" name="rk_meta[is_sold_out]" value="1" ' . checked($is_sold_out, '1', false) . '> Mark as Sold Out / Closed</label></td></tr>';

    echo '</table>';
}

add_action('save_post_raffle', 'rk_save_raffle_meta');
function rk_save_raffle_meta($post_id) {
    if (!isset($_POST['rk_raffle_meta_nonce']) || !wp_verify_nonce($_POST['rk_raffle_meta_nonce'], 'rk_save_raffle_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['rk_meta'])) {
        foreach ($_POST['rk_meta'] as $key => $value) {
            update_post_meta($post_id, sanitize_text_field($key), sanitize_textarea_field($value));
        }
    }
    // Handle unchecked checkbox
    if (!isset($_POST['rk_meta']['is_sold_out'])) {
        update_post_meta($post_id, 'is_sold_out', '0');
    }
}

/**
 * 5. EXPOSE NATIVE META TO REST API 
 */
add_action('rest_api_init', 'rk_register_raffle_meta_in_rest');
function rk_register_raffle_meta_in_rest() {
    register_rest_field('raffle', 'raffle_meta', [
        'get_callback' => function($object) {
            $post_id = $object['id'];
            $meta = [];
            $keys = ['price', 'max', 'sold', 'grand_prize', 'prize_list', 'expiry', 'is_sold_out'];
            foreach ($keys as $key) {
                $meta[$key] = get_post_meta($post_id, $key, true);
            }
            
            // Auto-calculate progress and remaining so the frontend doesn't have to
            $max = (int)($meta['max'] ?: 1);
            $sold = (int)($meta['sold'] ?: 0);
            $meta['remaining'] = max(0, $max - $sold);
            $meta['progress'] = min(100, round(($sold / $max) * 100));
            
            return $meta;
        }
    ]);
}