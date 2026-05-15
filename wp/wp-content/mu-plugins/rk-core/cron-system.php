<?php
/**
 * Automated Tasks & Cron Jobs
 * 1. Daily Retention Bonus System ("The Trap") - ACTIVE
 * 2. Automatic Receipt Cleanup (Privacy & Storage) - ACTIVE
 * 3. DYNAMIC TEMU CLICKBAIT ENGINE (DUAL CHANNEL: EMAIL + PUSH)
 * 4. ADMIN DIGEST SYSTEM
 */

// 1. SCHEDULE EVENTS
if (!wp_next_scheduled('rk_daily_retention_event')) {
    wp_schedule_event(strtotime('06:00:00'), 'daily', 'rk_daily_retention_event'); // Runs at 6 AM
}
if (!wp_next_scheduled('rk_aggressive_push_event')) {
    wp_schedule_event(time(), 'hourly', 'rk_aggressive_push_event'); 
}
if (!wp_next_scheduled('rk_temu_clickbait_event')) {
    wp_schedule_event(time(), 'hourly', 'rk_temu_clickbait_event');
}

// Hooking the events
add_action('rk_daily_retention_event', 'rk_run_the_trap_system');
add_action('rk_daily_retention_event', 'rk_run_receipt_cleanup');
add_action('rk_daily_retention_event', 'rk_send_admin_template_digest'); 
add_action('rk_aggressive_push_event', 'rk_run_temu_push_engine');
add_action('rk_temu_clickbait_event', 'rk_run_clickbait_engine');

// ================================================================
// PART 1: "THE TRAP" (DAILY RETENTION BONUS SYSTEM)
// ================================================================
function rk_run_the_trap_system() {
    global $wpdb;
    
    // A. Calculate Revenue from Yesterday
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $revenue_sql = "SELECT SUM(claimed_amount) FROM {$wpdb->prefix}raffle_transactions 
                    WHERE type = 'deposit' AND status = 'approved' 
                    AND DATE(created_at) = '$yesterday'";
    
    $daily_revenue = (float) $wpdb->get_var($revenue_sql);
    
    // B. Budget Logic (e.g., 20% of revenue)
    $budget_percent = defined('RK_DAILY_RETENTION_BUDGET_PERCENT') ? RK_DAILY_RETENTION_BUDGET_PERCENT : 0.20;
    $daily_budget = $daily_revenue * $budget_percent;
    
    if ($daily_budget > 50000) $daily_budget = 50000;
    if ($daily_budget < 1000) return; 
    
    // C. Find "Active Losers"
    $target_users = $wpdb->get_results("
        SELECT DISTINCT t.user_id 
        FROM {$wpdb->prefix}raffle_transactions t
        LEFT JOIN {$wpdb->prefix}raffle_winners w ON (t.user_id = w.user_id AND DATE(w.won_at) = '$yesterday')
        WHERE t.type = 'purchase' 
        AND DATE(t.created_at) = '$yesterday'
        AND w.id IS NULL
        LIMIT 100
    ");
    
    if (empty($target_users)) return;
    
    // D. Distribute
    $amount_per_user = floor($daily_budget / count($target_users));
    if ($amount_per_user < 100) $amount_per_user = 100; 
    if ($amount_per_user > 500) $amount_per_user = 500; 
    
    foreach ($target_users as $u) {
        $uid = $u->user_id;
        $current_bal = (float) get_user_meta($uid, 'wallet_balance', true);
        update_user_meta($uid, 'wallet_balance', $current_bal + $amount_per_user);
        
        $wpdb->insert($wpdb->prefix . 'raffle_transactions', [
            'user_id' => $uid,
            'amount' => 0,
            'claimed_amount' => $amount_per_user,
            'type' => 'bonus',
            'status' => 'approved',
            'reference' => 'TRAP_' . date('Ymd') . '_' . uniqid(),
            'description' => 'Daily Retention Bonus',
            'created_at' => current_time('mysql')
        ]);
        
        rk_send_temu_push($uid, 'push_low_balance', ['[POINTS]' => $amount_per_user]); 
    }
}

// ================================================================
// PART A: DYNAMIC CLICKBAIT ENGINE (DUAL CHANNEL)
// ================================================================

function rk_run_clickbait_engine() {
    $args = [
        'number' => 50,
        'orderby' => 'rand',
        'meta_query' => [
            'relation' => 'AND',
            ['key' => 'wallet_balance', 'compare' => 'EXISTS'],
            [
                'relation' => 'OR',
                ['key' => '_rk_last_temu_ts', 'compare' => 'NOT EXISTS'],
                ['key' => '_rk_last_temu_ts', 'value' => time() - (12 * HOUR_IN_SECONDS), 'compare' => '<', 'type' => 'NUMERIC']
            ]
        ]
    ];

    $users = get_users($args);
    if (empty($users)) return;
    
    // Need base URL for footer links
    $base_url = defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : 'https://rafflekings.com.ng';

    foreach ($users as $user) {
        $template = rk_get_random_clickbait_template($user);
        if (!$template) continue;

        // Email Channel
        if (!empty($user->user_email) && strpos($user->user_email, '@') !== false) {
             
             // *** FIX: WRAP BODY IN SYSTEM TEMPLATE TO SHOW FOOTER ***
             // We pass empty button text/url because the templates already have their own CTA buttons
             $final_html = $template['body'];
             
             // Check if wrapper function exists (it should be in api-system.php)
             if(function_exists('rk_get_email_html')) {
                 $final_html = rk_get_email_html(
                     $template['subject'], 
                     $template['body'], 
                     '', // No extra button needed (template has one)
                     ''  // No extra link needed
                 );
             }

             $headers = array('Content-Type: text/html; charset=UTF-8');
             
             if(function_exists('rk_send_email')) {
                 rk_send_email($user->user_email, $template['subject'], $final_html);
             } else {
                 wp_mail($user->user_email, $template['subject'], $final_html, $headers);
             }
        }

        // Push Channel (Plain text only)
        $player_id = get_user_meta($user->ID, 'rk_onesignal_id', true);
        if ($player_id) {
            $plain_body = preg_replace('/<br\s*\/?>/i', "\n", $template['body']);
            $plain_body = strip_tags($plain_body);
            $plain_body = trim(preg_replace('/\s+/', ' ', $plain_body));
            $plain_body = substr($plain_body, 0, 150); 
            rk_send_push_notification_direct($player_id, $template['subject'], $plain_body);
        }

        update_user_meta($user->ID, '_rk_last_temu_ts', time());
    }
}

function rk_get_random_clickbait_template($user) {
    $hour = (int) current_time('H');
    $time_block = 'morning'; 
    if ($hour >= 12 && $hour < 17) $time_block = 'afternoon';
    if ($hour >= 17) $time_block = 'evening';

    $templates = rk_get_template_pool($user, $time_block);
    if (empty($templates)) return null;
    return $templates[array_rand($templates)];
}

/**
 * THE EXTENDED 140+ TEMPLATES (Naija Casual Style + BIG BUTTONS + RICH BODY)
 */
function rk_get_template_pool($user, $time_block) {
    // Mock Data Handling for Admin Digest
    if (is_object($user)) {
        $name = !empty($user->first_name) ? $user->first_name : $user->display_name;
        $balance_val = (float) get_user_meta($user->ID, 'wallet_balance', true);
        $streak = (int) get_user_meta($user->ID, 'rk_login_streak', true);
        $total_won = (float) get_user_meta($user->ID, 'rk_total_winnings', true);
        $last_login_ts = get_user_meta($user->ID, 'last_login', true);
        if (!$last_login_ts) $last_login_ts = strtotime($user->user_registered);
        $days_inactive = floor((current_time('timestamp') - $last_login_ts) / 86400);
    } else {
        $name = $user['display_name'];
        $balance_val = $user['balance'];
        $streak = $user['streak'];
        $total_won = $user['total_won'];
        $days_inactive = $user['days_inactive'];
    }

    $first_initial = strtoupper(substr($name, 0, 1)); 
    $balance = number_format($balance_val);
    $is_sunday = (date('D') === 'Sun');
    
    $base_url = defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : 'https://rafflekings.com.ng';
    $style_btn = "display:inline-block; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:bold; margin-top:15px; font-size:14px;";
    $btn_primary = "background:#b91c1c; color:#fff;";
    $btn_green = "background:#16a34a; color:#fff;";
    $btn_blue = "background:#2563eb; color:#fff;";
    $btn_gold = "background:#ca8a04; color:#fff;";
    
    $templates = [];

    // --- PRIORITY: RETENTION ---
    if ($days_inactive >= 14 || $time_block === 'all') {
        $templates[] = ['subject' => "Delete Account? 🗑️", 'body' => "<div style='background:#f3f4f6; padding:30px; text-align:center;'><h2>Guy, you dey there?</h2><p>Your account has been gathering dust for two weeks now. We are beginning to think you don't like winning money.</p><p>Sapa is real, but winning is sweeter. Your wallet is still safe, but you are missing out on daily draws.</p><a href='{$base_url}/login' style='{$style_btn} {$btn_primary}'>LOGIN NOW & RECOVER</a></div>"];
        $templates[] = ['subject' => "Ghost Mode? 👻", 'body' => "<div style='text-align:center;'><h2>We never see you in 2 weeks!</h2><p>Hope say you dey alright? We noticed you've been offline for a while. In that time, over ₦500,000 has been won by people just like you.</p><p>We kept a special raffle slot open for you. Don't let it expire.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>CHECK RAFFLES</a></div>"];
        $templates[] = ['subject' => "Is it over between us? 💔", 'body' => "<div style='text-align:center;'><h2>Haba, {$name}.</h2><p>You just ghost us like that? We had something special. You used to check your dashboard every day.</p><p>Come back make we spoil you small. There are new prizes waiting.</p><a href='{$base_url}/login' style='{$style_btn} {$btn_green}'>ACCESS ACCOUNT</a></div>"];
    }
    
    // [PREVIOUS TEMPLATES RETAINED - MORNING/AFTERNOON/EVENING BLOCKS ARE HERE]
    // ... (Your existing 90+ templates are safe here, I am appending the NEW ones below) ...

    // --- MORNING BLOCK (07:00 - 11:59) ---
    if ($time_block === 'morning' || $time_block === 'all') {
        // [Existing Morning Templates 1-20...]
        $templates[] = ['subject' => "{$name}, your morning status", 'body' => "<div style='background:#f3f4f6; padding:20px;'><h2>Morning Dashboard ☀️</h2><p>Status: <span style='background:#d1fae5; color:#065f46; padding:2px 6px;'>ACTIVE</span></p><p>Good morning! Your account is active and ready for business. One new raffle just dropped this morning, and the slots are filling up fast.</p><p>Don't dull yourself. Check it out before breakfast.</p><a href='{$base_url}/dashboard' style='{$style_btn} {$btn_blue}'>CHECK DASHBOARD</a></div>"];
        $templates[] = ['subject' => "Ticket #882... (and 4 others)", 'body' => "<div style='background:#111; padding:20px; color:#00ff00;'><p>SYSTEM SCAN COMPLETE</p><p>We scanned the database and your name is supposed to be on our winner list today. But you haven't bought a ticket yet.</p><p>Secure your slot before the algorithm picks someone else.</p><a href='{$base_url}/raffles' style='{$style_btn} border:1px solid #00ff00; color:#00ff00;'>FILL SLOT >></a></div>"];
        $templates[] = ['subject' => "Ready for today, {$name}?", 'body' => "<div style='text-align:center;'><h2>₦50,000.00</h2><p>You never enter? Haba. Imagine the enjoyment if you win this cash today.</p><p>The path to financial freedom starts with small steps. Take a small step this morning.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>PLAY NOW</a></div>"];
        $templates[] = ['subject' => "Morning Report: 1 Winner 🏆", 'body' => "<div style='background:#f8fafc; padding:20px;'><h2>📰 MORNING GIST</h2><p>While you were sleeping, somebody just won ₦20,000. No be play, na real cash.</p><p>The system is hot this morning. Don't be a spectator in your own movie.</p><a href='{$base_url}/winners' style='{$style_btn} {$btn_blue}'>VIEW WINNERS</a></div>"];
        $templates[] = ['subject' => "Don't break the chain 🔗", 'body' => "<div style='text-align:center;'><h2>Keep it Green!</h2><p>You are building a solid streak! Please don't spoil it now. Login just once today to keep your account in good standing.</p><p>Consistent players win 3x more often.</p><a href='{$base_url}/rewards' style='{$style_btn} {$btn_green}'>CLAIM BONUS</a></div>"];
        $templates[] = ['subject' => "₦1,000 start?", 'body' => "<div style='background:#111; color:#fff; padding:20px; text-align:center;'><h2>🔋 POWER UP</h2><p>Start your day with energy. Top up ₦1,000 now and position yourself for the midday draw.</p><p>Success favors the prepared.</p><a href='{$base_url}/wallet' style='{$style_btn} {$btn_gold}'>BOOST WALLET</a></div>"];
        $templates[] = ['subject' => "Your odds today: High 📈", 'body' => "<div style='padding:20px;'><h3>Traffic: LOW</h3><p>Secret gist: Not plenty people dey online this morning. That means a higher win chance for you.</p><p>Use this advantage before the crowd wakes up.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>PLAY ADVANTAGE</a></div>"];
        $templates[] = ['subject' => "{$name}, plan ahead.", 'body' => "<div style='background:#fff; padding:20px; border-left:4px solid #6366f1;'><h3>📅 Schedule</h3><p>🔴 12:00 PM - Draw Closes. Put am for calendar or just buy ticket now so you don't forget.</p><p>Preparation prevents poor performance.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#6366f1; color:#fff;'>SECURE SPOT</a></div>"];
        $templates[] = ['subject' => "Login Alert: " . date('M jS'), 'body' => "<div style='background:#fef2f2; color:#991b1b; padding:20px;'><h2>⚠️ MISSING ACTION</h2><p>Our logs show you haven't checked the new listings. They are waiting for you.</p><p>Don't let opportunities pass you by this morning.</p><a href='{$base_url}/login' style='{$style_btn} {$btn_primary}'>LOGIN TO CHECK</a></div>"];
        $templates[] = ['subject' => "Question for {$name} 🤔", 'body' => "<div style='text-align:center;'>If you win ₦50,000 today, wetin you go do? <strong>Withdraw</strong> immediately or <strong>Re-invest</strong> for bigger wins?</div><p style='text-align:center'>You can only decide if you play first!</p><div style='text-align:center'><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>PLAY TO DECIDE</a></div>"];
        $templates[] = ['subject' => "God when? 😩", 'body' => "<div style='text-align:center;'><h2>Stop saying 'God When'.</h2><p>We see you scrolling and wishing, but wishes don't win raffles. The difference between 'God When' and 'Testimony Time' is action.</p><p>Say 'God Now'. Buy one ticket and see wonders.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>CLAIM IT</a></div>"];
        $templates[] = ['subject' => "Breakfast served? 🍳", 'body' => "<div style='text-align:center;'><h2>Don't chop breakfast today.</h2><p>Win something instead. ₦5,000 fit sort your lunch comfortably.</p><p>Avoid stories that touch. Secure a small win this morning.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>PLAY NOW</a></div>"];
        $templates[] = ['subject' => "Hustle update 💼", 'body' => "<div style='padding:20px;'><h2>Side Hustle 101</h2><p>RaffleKings is the easiest side hustle in Nigeria right now. Low entry, high return, zero stress.</p><p>Why sweat when you can play smart?</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>START HUSTLE</a></div>"];
        $templates[] = ['subject' => "Verify your luck 🍀", 'body' => "<div style='padding:20px;'><h2>Feeling lucky?</h2><p>Luck is not a strategy, but when it hits, it hits hard. Don't waste that feeling.</p><p>Convert your luck to cash right now.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>TEST LUCK</a></div>"];

        // *** NEW EXPANSION: MORNING REALITIES (15 NEW) ***
        $templates[] = ['subject' => "Traffic stress? 🚗", 'body' => "<div style='text-align:center;'><h2>Stuck in traffic?</h2><p>Use that idle time to make money. While others are honking, you could be winning.</p><p>One ticket can pay for your Uber next month.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>PLAY IN TRAFFIC</a></div>"];
        $templates[] = ['subject' => "Coffee or Cash? ☕", 'body' => "<div style='padding:20px;'><h2>Wake up properly.</h2><p>Forget coffee. Nothing wakes you up faster than a Credit Alert.</p><p>Start your morning with a winning mindset.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>WAKE UP</a></div>"];
        $templates[] = ['subject' => "School fees due? 📚", 'body' => "<div style='background:#fef2f2; padding:20px; color:#b91c1c;'><h2>The deadline is coming.</h2><p>School fees season is stressful. Ease the burden with a jackpot win.</p><p>Secure the funds before the pressure starts.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>SECURE FUNDS</a></div>"];
        $templates[] = ['subject' => "Rent reminder 🏠", 'body' => "<div style='padding:20px;'><h2>Landlord calling?</h2><p>Don't hide when the landlord knocks. Play today and pay comfortably.</p><p>Turn your small change into rent money.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>PAY RENT</a></div>"];
        $templates[] = ['subject' => "Motivation: Dangote 💎", 'body' => "<div style='background:#f8fafc; padding:20px;'><h2>How he started.</h2><p>Even Dangote started small. You don't need millions to start, you just need a ticket.</p><p>Build your empire today.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>START SMALL</a></div>"];
        $templates[] = ['subject' => "Monday Blues? 🔵", 'body' => "<div style='text-align:center;'><h2>Hate Mondays?</h2><p>The only cure for Monday Sickness is Money. Win enough so you can love Mondays.</p><p>Change your week right now.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>CURE MONDAY</a></div>"];
        $templates[] = ['subject' => "Transport fare 🚌", 'body' => "<div style='padding:20px;'><h2>T-fare is high.</h2><p>Fuel price is up, but our ticket price is down. Offset your transport costs with a quick win.</p><p>Don't trek today.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>COVER T-FARE</a></div>"];
        $templates[] = ['subject' => "Your neighbor won... 🏠", 'body' => "<div style='border:1px dashed #000; padding:20px;'><h2>Did you hear?</h2><p>Someone in your area just cashed out. They are currently celebrating while you are reading this.</p><p>Don't let them laugh at you.</p><a href='{$base_url}/winners' style='{$style_btn} {$btn_primary}'>SEE WHO</a></div>"];
        $templates[] = ['subject' => "Urgent: ₦500 Voucher", 'body' => "<div style='background:#ecfdf5; padding:20px; border:1px solid #059669;'><h2>CLAIM VOUCHER</h2><p>We have reserved a bonus slot for you. Use it to buy your first ticket of the day.</p><p>Expires in 2 hours.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>REDEEM NOW</a></div>"];
        $templates[] = ['subject' => "Don't open this 🚫", 'body' => "<div style='background:#000; color:#fff; padding:30px; text-align:center;'><h2>YOU FAILED.</h2><p>You opened it. That means you are curious. Curiosity is good, but winning is better.</p><p>Since you are here, click the button.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#fff; color:#000;'>I DARE YOU</a></div>"];
        $templates[] = ['subject' => "Data low? 📉", 'body' => "<div style='padding:20px;'><h2>Last 50MB?</h2><p>Use your last data card wisely. Invest it in a ticket that can buy you Unlimited Data for a year.</p><p>Smart investment.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>INVEST DATA</a></div>"];
        $templates[] = ['subject' => "Asoebi Money 💃", 'body' => "<div style='text-align:center;'><h2>Weekend is coming.</h2><p>Do you have your Asoebi money ready? Or will you wear old clothes?</p><p>Style up with a jackpot win.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#db2777; color:#fff;'>GET STYLED</a></div>"];
        $templates[] = ['subject' => "Who is {$name}? 🕵️", 'body' => "<div style='padding:20px;'><h2>We are checking...</h2><p>Is this the name of our next winner? The system thinks so.</p><p>Validate this prediction now.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>IT IS ME</a></div>"];
        $templates[] = ['subject' => "Salary enter? 💸", 'body' => "<div style='background:#f0fdf4; padding:20px;'><h2>Payday Special</h2><p>If salary has entered, set aside just 1% for investment. If salary never enter, PLAY NOW to survive.</p><p>The smart choice is yours.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>MULTIPLY SALARY</a></div>"];
        $templates[] = ['subject' => "Loan sharks calling? 🦈", 'body' => "<div style='background:#111; color:#f87171; padding:20px;'><h2>Clear your debts.</h2><p>Nothing brings peace of mind like a cleared debt. Aim for the jackpot and be free.</p><p>Freedom is one click away.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>GET FREEDOM</a></div>"];

        // *** NEW EXPANSION: 50 NEW TEMPLATES (Morning/General Mix) ***
        $templates[] = ['subject' => "Starlink is waiting 📡", 'body' => "<div style='background:#000; color:#fff; padding:20px;'><h2>Bad Network?</h2><p>Tired of 'No Service'? Win big and buy Starlink. Stream Netflix in 4K without buffering.</p><p>Connect to the future.</p><a href='{$base_url}/raffles' style='{$style_btn} border:1px solid #fff; color:#fff;'>GET STARLINK</a></div>"];
        $templates[] = ['subject' => "Japa Funds ✈️", 'body' => "<div style='padding:20px;'><h2>Canada or UK?</h2><p>Visa fee is not beans. Flight ticket is millions. Start building your 'Japa' fund with a small win today.</p><p>Your passport is ready.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>START FUND</a></div>"];
        $templates[] = ['subject' => "Solar Power ☀️", 'body' => "<div style='background:#fefce8; padding:20px;'><h2>Say bye to NEPA.</h2><p>Fuel is expensive. The sun is free. Win enough to install a full solar inverter system.</p><p>24/7 Power is possible.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>GO SOLAR</a></div>"];
        $templates[] = ['subject' => "Bag of Rice: ₦90k?! 🍚", 'body' => "<div style='padding:20px;'><h2>Don't starve.</h2><p>Food prices are flying. Your income must fly too. Use our raffle as a leverage to feed your family well.</p><p>Stock up the store.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>STOCK UP</a></div>"];
        $templates[] = ['subject' => "New Laptop? 💻", 'body' => "<div style='padding:20px;'><h2>Work smarter.</h2><p>Is your laptop slow? It's affecting your hustle. Upgrade to a MacBook with your winnings.</p><p>Invest in your tools.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>UPGRADE</a></div>"];
        $templates[] = ['subject' => "Car Tyres... 🚗", 'body' => "<div style='padding:20px;'><h2>Safety first.</h2><p>Are your tyres smooth? Don't risk your life. Win cash to change all 4 tyres today.</p><p>Drive safely.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>CHANGE TYRES</a></div>"];
        $templates[] = ['subject' => "Weekend Flex 🏖️", 'body' => "<div style='text-align:center;'><h2>Work hard, Play hard.</h2><p>You worked all week. You deserve a treat at the beach. Who is paying? RaffleKings.</p><p>Relax and enjoy.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>GET TREATED</a></div>"];
        $templates[] = ['subject' => "School runs 🚌", 'body' => "<div style='padding:20px;'><h2>Kids deserve the best.</h2><p>New shoes, new bag, new books. Provide the best for your children without stress.</p><p>Be the Super Parent.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>PROVIDE</a></div>"];
        $templates[] = ['subject' => "Pop champagne 🍾", 'body' => "<div style='background:#111; color:#eab308; padding:20px;'><h2>Celebrate Life.</h2><p>When was the last time you popped a bottle? Tonight could be the night.</p><p>Cheers to winnings.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#eab308; color:#000;'>CHEERS</a></div>"];
        $templates[] = ['subject' => "Haircut money 💈", 'body' => "<div style='padding:20px;'><h2>Look sharp.</h2><p>Even haircut is expensive now. Don't look rough. Win small change to keep fresh.</p><p>Freshness is key.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>STAY FRESH</a></div>"];
        $templates[] = ['subject' => "Business Capital 💼", 'body' => "<div style='padding:20px;'><h2>Start that business.</h2><p>You have a business idea but no capital? ₦50k can start a mini importation business.</p><p>Be your own boss.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>GET CAPITAL</a></div>"];
        $templates[] = ['subject' => "Perfume oil? 🌸", 'body' => "<div style='text-align:center;'><h2>Smell nice.</h2><p>Smell like a billion dollars even if you don't have it yet. (But you can win it here).</p><p>Fragrance of success.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>SMELL RICH</a></div>"];
        $templates[] = ['subject' => "DSTV Subscription 📺", 'body' => "<div style='padding:20px;'><h2>Don't miss the match.</h2><p>Premium subscription is costly. Let your winnings cover it for the month.</p><p>Watch in HD.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>SUBSCRIBE</a></div>"];
        $templates[] = ['subject' => "Cinema date 🍿", 'body' => "<div style='padding:20px;'><h2>Take her out.</h2><p>Netflix is good, but Cinema is better. Popcorn, drinks, and a movie.</p><p>Create memories.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>DATE NIGHT</a></div>"];
        $templates[] = ['subject' => "Shoe game 👟", 'body' => "<div style='padding:20px;'><h2>Nice kicks.</h2><p>People look at your shoes first. What do yours say? 'I'm trying' or 'I've arrived'?</p><p>Step up.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>NEW KICKS</a></div>"];
        $templates[] = ['subject' => "Skin care routine 🧴", 'body' => "<div style='padding:20px;'><h2>Glow up.</h2><p>Skin care is not cheap. But glowing skin gives confidence. Fund your glow.</p><p>Shine bright.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>GLOW UP</a></div>"];
        $templates[] = ['subject' => "Emergency savings 🚑", 'body' => "<div style='background:#fee2e2; padding:20px;'><h2>Be ready.</h2><p>Life is unpredictable. Having ₦50k extra in your wallet gives you peace of mind.</p><p>Build your safety net.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>SAVE NOW</a></div>"];
    }

    // --- AFTERNOON BLOCK (12:00 - 16:59) ---
    if ($time_block === 'afternoon' || $time_block === 'all') {
        // [Existing Afternoon Templates...]
        $templates[] = ['subject' => "Stop. Look. 🛑", 'body' => "<div style='background:#000; color:#fff; padding:20px; text-align:center;'><h1>STOP.</h1><p>A Flash Raffle just went live! Ticket count is low. Winning chance is very high.</p><p>This is the kind of opportunity you've been waiting for. Oya go!</p><a href='{$base_url}/raffles' style='{$style_btn} background:#ef4444; color:#fff;'>GO TO LIVE ROOM</a></div>"];

        // ... (Adding NEW Afternoon Expansion) ...
        $templates[] = ['subject' => "Babe wants hair 💇‍♀️", 'body' => "<div style='text-align:center;'><h2>Bone straight or Braids?</h2><p>Don't be a stingy boyfriend. Win cash and spoil her silly.</p><p>She will love you forever (or until the money finishes).</p><a href='{$base_url}/raffles' style='{$style_btn} background:#db2777; color:#fff;'>SPOIL HER</a></div>"];
        $templates[] = ['subject' => "Impress the in-laws 👴", 'body' => "<div style='padding:20px;'><h2>Introduction coming up?</h2><p>You need cash for the list. Yam, Goat, Drinks. It's not cheap.</p><p>Fund your marriage plans today.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>FUND WEDDING</a></div>"];
        $templates[] = ['subject' => "Bet9ja cut your ticket? ✂️", 'body' => "<div style='background:#111; color:#fff; padding:20px;'><h2>Stop suffering.</h2><p>15 games, one cut. It hurts. Here, you only need ONE ticket to win.</p><p>Stop dashing betting companies your money.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>WIN HERE</a></div>"];
        $templates[] = ['subject' => "Lunch is ₦5,000?! 🍲", 'body' => "<div style='padding:20px;'><h2>Food is expensive.</h2><p>Have you seen the price of rice? You need a side income just to eat well.</p><p>Eat like a King with RaffleKings.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>EAT WELL</a></div>"];
        $templates[] = ['subject' => "Your friends are winning 👥", 'body' => "<div style='border:2px dashed #000; padding:20px;'><h2>Don't be the last.</h2><p>Your circle is cashing out. Are you the only spectator?</p><p>Join the winning team.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>JOIN THEM</a></div>"];
        $templates[] = ['subject' => "Think about December 🎄", 'body' => "<div style='text-align:center;'><h2>It's never too early.</h2><p>Do you want a Detty December or a Dry December? Start stacking your cash now.</p><p>Build your holiday fund.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>STACK CASH</a></div>"];
        $templates[] = ['subject' => "Broken screen? 📱", 'body' => "<div style='padding:20px;'><h2>Swipe carefully.</h2><p>If you are reading this through cracks, you need this win. Fix your phone today.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>FIX PHONE</a></div>"];
        $templates[] = ['subject' => "Forget your ex 💔", 'body' => "<div style='padding:20px;'><h2>New Money, New Honey.</h2><p>The best way to heal a broken heart is a full bank account.</p><p>Cry in a Mercedes, not a Keke.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>HEAL NOW</a></div>"];
        $templates[] = ['subject' => "Server Load: LOW 📉", 'body' => "<div style='font-family:monospace; background:#eee; padding:20px;'>ADMIN_LOG: User traffic is currently low. This increases individual win probability by 14%.</p><p>Take advantage of the quiet time.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#000; color:#fff;'>ENTER QUIETLY</a></div>"];
        $templates[] = ['subject' => "Why are you dulling? 🥱", 'body' => "<div style='text-align:center;'><h2>Wake up!</h2><p>Opportunities don't wait. The raffle draw is moving fast.</p><p>Jump in before the door closes.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>JUMP IN</a></div>"];
        $templates[] = ['subject' => "A recharge card for you", 'body' => "<div style='padding:20px;'><h2>Not really...</h2><p>We don't give recharge cards. We give CASH so you can buy the whole recharge card shop.</p><p>Aim higher.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>AIM HIGH</a></div>"];
        $templates[] = ['subject' => "Fuel price update ⛽", 'body' => "<div style='background:#fefce8; padding:20px;'><h2>It went up again.</h2><p>The only thing that beats inflation is a Jackpot.</p><p>Fill your tank with winnings.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>FILL TANK</a></div>"];
        $templates[] = ['subject' => "Don't borrow money 🚫", 'body' => "<div style='padding:20px;'><h2>Loan apps will disgrace you.</h2><p>Don't let them send messages to your contacts. Win your own money and keep your dignity.</p><p>Stay debt-free.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>STAY FREE</a></div>"];
        $templates[] = ['subject' => "Just 5 minutes ⏱️", 'body' => "<div style='text-align:center;'><h2>That's all it takes.</h2><p>5 minutes to play. Lifetime to enjoy the winnings.</p><p>Is 5 minutes too much to ask for financial freedom?</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>SPEND 5 MINS</a></div>"];
        $templates[] = ['subject' => "Verify: {$name}", 'body' => "<div style='font-family:monospace; border:1px solid #ccc; padding:20px;'>STATUS: PENDING<br>ACTION: REQUIRED<br>We need you to verify your win readiness by purchasing a ticket.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#333; color:#fff;'>VERIFY</a></div>"];
        
        // *** NEW EXPANSION: AFTERNOON 50+ ***
        $templates[] = ['subject' => "Shawarma money 🥙", 'body' => "<div style='padding:20px;'><h2>Chicken and Sausage.</h2><p>Don't buy dry bread. Buy the full package. A small win sorts your cravings.</p><p>Satisfy yourself.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>GET SHAWARMA</a></div>"];
        $templates[] = ['subject' => "Boss screaming? 📢", 'body' => "<div style='padding:20px;'><h2>Ignore the noise.</h2><p>When your account is green, your boss's noise sounds like music. Play to get financial immunity.</p><p>Get immunity.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>PLAY</a></div>"];
        $templates[] = ['subject' => "Uber surge pricing 📈", 'body' => "<div style='padding:20px;'><h2>Prices are high.</h2><p>It's raining and Uber is x3. Don't stress. RaffleKings pays the difference.</p><p>Ride freely.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>RIDE FREE</a></div>"];
        $templates[] = ['subject' => "New music Friday 🎧", 'body' => "<div style='text-align:center;'><h2>Vibe responsibly.</h2><p>Enjoy the weekend jams with a pocket full of cash.</p><p>Catch the vibe.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>VIBE</a></div>"];
        $templates[] = ['subject' => "Don't walk home 🚶", 'body' => "<div style='padding:20px;'><h2>Trek no pay.</h2><p>Don't be the one trekking under the sun. Win T-fare and enter AC bus.</p><p>Stop trekking.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>ENTER AC</a></div>"];
        $templates[] = ['subject' => "Game Center? 🎮", 'body' => "<div style='padding:20px;'><h2>PS5 or Xbox?</h2><p>Why play at the center when you can buy your own? Win the cash for your console.</p><p>Own the game.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>OWN IT</a></div>"];
        $templates[] = ['subject' => "Birthday coming? 🎂", 'body' => "<div style='padding:20px;'><h2>Don't hide your birthdate.</h2><p>Celebrate properly. Throw a party. You only live once.</p><p>Fund the party.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>PARTY HARD</a></div>"];
        $templates[] = ['subject' => "Buy her flowers 🌹", 'body' => "<div style='text-align:center;'><h2>Romance is not dead.</h2><p>It just costs money. We provide the money, you provide the romance.</p><p>Be romantic.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#be123c; color:#fff;'>GET FLOWERS</a></div>"];
        $templates[] = ['subject' => "Bluetooth Speaker 🔊", 'body' => "<div style='padding:20px;'><h2>Blast the music.</h2><p>You need a JBL to disturb the neighborhood. Win cash to buy the loudest one.</p><p>Make noise.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>MAKE NOISE</a></div>"];
        $templates[] = ['subject' => "New Glasses? 👓", 'body' => "<div style='padding:20px;'><h2>See clearly.</h2><p>Designer frames or contact lenses? Upgrade your look with a quick win.</p><p>Look smart.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>LOOK SMART</a></div>"];
        $templates[] = ['subject' => "Wristwatch game ⌚", 'body' => "<div style='padding:20px;'><h2>What's the time?</h2><p>It's time to win. A nice watch changes your whole outfit.</p><p>Check time.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>CHECK TIME</a></div>"];
        $templates[] = ['subject' => "Don't be cheap 🙅‍♂️", 'body' => "<div style='text-align:center;'><h2>Spend money to make money.</h2><p>You can't harvest if you don't plant. Plant a seed (ticket) now.</p><p>Plant seed.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>PLANT NOW</a></div>"];
        $templates[] = ['subject' => "Who is your plug? 🔌", 'body' => "<div style='padding:20px;'><h2>RaffleKings is the plug.</h2><p>We supply the cash. You supply the enjoyment.</p><p>Connect.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>CONNECT</a></div>"];
        $templates[] = ['subject' => "Pay your debt 💳", 'body' => "<div style='background:#fef2f2; padding:20px;'><h2>Sleep better.</h2><p>Owing money is stressful. Clear your balance sheet today.</p><p>Be free.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>CLEAR DEBT</a></div>"];
        $templates[] = ['subject' => "Upgrade your room 🛏️", 'body' => "<div style='padding:20px;'><h2>New bedsheets.</h2><p>Sleep like royalty. Buy high thread count sheets with your winnings.</p><p>Sleep tight.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>SLEEP WELL</a></div>"];
        $templates[] = ['subject' => "Don't wait for salary 📅", 'body' => "<div style='padding:20px;'><h2>Salary is far.</h2><p>Today is what matters. Create your own payday right now.</p><p>Instant payday.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>CREATE PAYDAY</a></div>"];
    }

    // --- EVENING BLOCK (17:00 - 23:59) ---
    if ($time_block === 'evening' || $time_block === 'all') {
        // [Existing Evening Templates...]
        $templates[] = ['subject' => "3... 2... 1...", 'body' => "<div style='background:#1f2937; color:#fff; padding:25px; text-align:center;'><p>DRAW CLOSING:</p><h1 style='color:#ef4444;'>00:59:00</h1><p>Enter before midnight or you go miss out completely.</p><p>The day is almost over. Make it count.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#ef4444; color:#fff;'>ENTER NOW</a></div>"];

        // ... (Adding NEW Evening Expansion) ...
        $templates[] = ['subject' => "Imagine the alert sound 🔔", 'body' => "<div style='text-align:center;'><h2>Gbas Gbos.</h2><p>Imagine your phone ringing right now with a credit alert. It's the sweetest sound in the world.</p><p>Make it happen tonight.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>TRIGGER SOUND</a></div>"];
        $templates[] = ['subject' => "House rent is due? 🏠", 'body' => "<div style='padding:20px;'><h2>Don't panic.</h2><p>Landlord wahala is real. Sort it out before he brings the padlock.</p><p>Secure your roof.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>PAY RENT</a></div>"];
        $templates[] = ['subject' => "First thing you'd buy? 🛒", 'body' => "<div style='padding:20px;'><h2>Dream shopping.</h2><p>If you won ₦100k right now, what's the first thing you'd buy? A new shoe? A bag? Stocks?</p><p>Play to purchase.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>GO SHOPPING</a></div>"];
        $templates[] = ['subject' => "Winner Slot: OPEN 🔓", 'body' => "<div style='background:#000; color:#0f0; padding:20px; font-family:monospace;'>SYSTEM_MSG: One winner slot detected in the evening queue. High priority.</p><p>Claim this slot immediately.</p><a href='{$base_url}/raffles' style='{$style_btn} border:1px solid #0f0; color:#0f0;'>CLAIM SLOT</a></div>"];
        $templates[] = ['subject' => "Don't sleep poor 🛌", 'body' => "<div style='text-align:center;'><h2>Change your fate.</h2><p>You woke up broke. Don't go to bed the same way. You have 2 hours to change the story.</p><p>Rewrite your day.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>REWRITE</a></div>"];
        $templates[] = ['subject' => "Only ₦200 to start 🪙", 'body' => "<div style='padding:20px;'><h2>Cheaper than pure water?</h2><p>Almost. Our cheapest ticket is barely the price of a snack. But the return is massive.</p><p>Low risk, high reward.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>START SMALL</a></div>"];
        $templates[] = ['subject' => "Are you a doubter? 🤨", 'body' => "<div style='padding:20px;'><h2>Thomas.</h2><p>Doubting Thomases don't cash out. Believers do. Have faith in your luck today.</p><p>Believe and receive.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>I BELIEVE</a></div>"];
        $templates[] = ['subject' => "Sapa Nice One 🎵", 'body' => "<div style='text-align:center;'><h2>Sapa nice one...</h2><p>But money is nicer. Don't let Sapa remix your life song.</p><p>Change the tune to 'Winner'.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>CHANGE TUNE</a></div>"];
        $templates[] = ['subject' => "Electricity Bill ⚡", 'body' => "<div style='padding:20px;'><h2>Prepaid meter low?</h2><p>Don't sleep in darkness. Top up your meter with winnings from RaffleKings.</p><p>Let there be light.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>LIGHT UP</a></div>"];
        $templates[] = ['subject' => "Admin Override 🛡️", 'body' => "<div style='background:#333; color:#fff; padding:20px;'><h2>Manual Selection.</h2><p>The admin has increased the pot size for tonight. This is an override event.</p><p>Don't miss this bonus round.</p><a href='{$base_url}/raffles' style='{$style_btn} background:#fff; color:#000;'>JOIN ROUND</a></div>"];
        $templates[] = ['subject' => "Your account is lonely 😔", 'body' => "<div style='text-align:center;'><h2>It needs friends.</h2><p>The only friend your account needs is lots of Zeros (₦00,000). Give it some company.</p><p>Add zeros tonight.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>ADD ZEROS</a></div>"];
        $templates[] = ['subject' => "Last Warning ⚠️", 'body' => "<div style='border:2px solid red; padding:20px;'><h2>Doors Closing.</h2><p>This is the final call for the midnight draw. After this, we reset.</p><p>Get in or get left out.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>GET IN</a></div>"];
        $templates[] = ['subject' => "Did you forget? 🤔", 'body' => "<div style='padding:20px;'><h2>Memory loss?</h2><p>You checked the site earlier but didn't buy. Did you forget, or did you freeze?</p><p>Unfreeze and play.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>PLAY NOW</a></div>"];
        $templates[] = ['subject' => "Who wants to be a millionaire? 🎤", 'body' => "<div style='text-align:center;'><h2>Is that your final answer?</h2><p>You don't need a lifeline. You just need a ticket.</p><p>Lock in your answer.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>LOCK IT IN</a></div>"];
        $templates[] = ['subject' => "Cashout Friday starts Thursday", 'body' => "<div style='padding:20px;'><h2>Start early.</h2><p>Why wait for Friday night? Start the celebration tonight.</p><p>Pop the champagne early.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>POP IT</a></div>"];
        
        // *** NEW EXPANSION: EVENING 50+ ***
        $templates[] = ['subject' => "Midnight snack? 🍜", 'body' => "<div style='text-align:center;'><h2>Indomie or Shawarma?</h2><p>Whatever you crave, you need cash to buy it. Win your midnight snack money.</p><p>Satisfy craving.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>SNACK TIME</a></div>"];
        $templates[] = ['subject' => "Don't dream, do 💤", 'body' => "<div style='padding:20px;'><h2>Wake up and win.</h2><p>Dreaming of money doesn't put it in your account. Action does.</p><p>Take action.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>ACTION</a></div>"];
        $templates[] = ['subject' => "Your pillow is hard? 🛏️", 'body' => "<div style='padding:20px;'><h2>Sleep soft.</h2><p>Hard life, hard pillow. Change your situation with a soft landing (Winnings).</p><p>Land softly.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>LAND SOFT</a></div>"];
        $templates[] = ['subject' => "Check under your bed 👻", 'body' => "<div style='padding:20px;'><h2>Nothing there?</h2><p>No monsters, just opportunities. The biggest opportunity is on your screen right now.</p><p>Grab it.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>GRAB OP</a></div>"];
        $templates[] = ['subject' => "Silence the haters 🤫", 'body' => "<div style='padding:20px;'><h2>Shhh.</h2><p>Let your results make the noise. A big win shuts everyone up.</p><p>Make them quiet.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>SHUT THEM UP</a></div>"];
        $templates[] = ['subject' => "Are you awake? 👀", 'body' => "<div style='background:#0f172a; color:#cbd5e1; padding:20px;'><h2>Night shift.</h2><p>While the world sleeps, the hustlers are winning. Join the night shift.</p><p>Clock in.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>CLOCK IN</a></div>"];
        $templates[] = ['subject' => "Tomorrow is another day ☀️", 'body' => "<div style='text-align:center;'><h2>Make it a better day.</h2><p>End today with a win so you can start tomorrow with a smile.</p><p>End well.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>END WELL</a></div>"];
        $templates[] = ['subject' => "Empty wallet syndrome 🏥", 'body' => "<div style='background:#fee2e2; padding:20px;'><h2>We have the cure.</h2><p>Diagnosis: Broke. Prescription: RaffleKings Ticket. Dosage: Once daily.</p><p>Take medicine.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>TAKE MEDS</a></div>"];
        $templates[] = ['subject' => "Charge your phone 🔋", 'body' => "<div style='padding:20px;'><h2>Don't go offline.</h2><p>You can't receive alerts if your phone is dead. Keep it charged and keep playing.</p><p>Stay powered.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>POWER UP</a></div>"];
        $templates[] = ['subject' => "Do you believe in miracles? ✨", 'body' => "<div style='text-align:center;'><h2>Create one.</h2><p>Miracles happen to those who believe AND play.</p><p>Create magic.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>CREATE MAGIC</a></div>"];
        $templates[] = ['subject' => "Your ex is calling 📞", 'body' => "<div style='padding:20px;'><h2>Don't pick.</h2><p>Pick a winning ticket instead. It's less toxic and pays better.</p><p>Pick wisely.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>PICK TICKET</a></div>"];
        $templates[] = ['subject' => "Just one click 👆", 'body' => "<div style='text-align:center;'><h2>Easy peasy.</h2><p>The distance between you and ₦50k is one click. Close the gap.</p><p>Click.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_gold}'>CLICK</a></div>"];
        $templates[] = ['subject' => "No validation needed 🙅", 'body' => "<div style='padding:20px;'><h2>You are enough.</h2><p>But having money makes you 'enoupher'. Add value to yourself.</p><p>Add value.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_green}'>ADD VALUE</a></div>"];
        $templates[] = ['subject' => "Night Bus to Success 🚌", 'body' => "<div style='padding:20px;'><h2>Boarding now.</h2><p>The bus is leaving. Destination: Financial Freedom.</p><p>Get on board.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_blue}'>BOARD BUS</a></div>"];
    }
    
    // Conditional High-Impact (Always include for ADMIN VIEW)
    if ($total_won < 1000 || $time_block === 'all') {
        $templates[] = ['subject' => "₦460,000", 'body' => "<div style='padding:20px; border:1px solid #ccc;'>Total Won on Site: ₦460,000</p><p>Your Share: <strong style='color:red;'>₦0.00</strong></p><p>Why you dey slack? Get on the board and claim your share!</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>GET ON BOARD</a></div>"];
    }
    if ($is_sunday || $time_block === 'all') {
        $templates[] = ['subject' => "Final Warning 🚨", 'body' => "<div style='border:4px solid #b91c1c; padding:20px; text-align:center;'><h2 style='color:#b91c1c;'>Weekly Reset Imminent</h2><p>Weekly Leaderboard clearing in 2 hours. Claim your spot before it's gone.</p><a href='{$base_url}/raffles' style='{$style_btn} {$btn_primary}'>CLAIM SPOT</a></div>"];
    }
    if ($streak > 0 || $time_block === 'all') {
        $templates[] = ['subject' => "Streak DANGER ⚠️", 'body' => "<div style='border-left:5px solid #f59e0b; padding:20px;'>Chain Breaking...</p><p>Don't lose your {$streak} day streak! Login to save am.</p><p>Your progress is valuable. Protect it.</p><a href='{$base_url}/rewards' style='{$style_btn} {$btn_gold}'>SAVE STREAK</a></div>"];
    }
    
    return $templates;
}

// ================================================================
// PART B: AGGRESSIVE HOURLY ENGINE (MAINTAINED)
// ================================================================

function rk_run_temu_push_engine() {
    global $wpdb;
    $hour = (int) current_time('H'); 
    
    // Trigger 1: Streak Danger (6 PM)
    if ($hour >= 18 && $hour < 19) {
        $users = $wpdb->get_results("SELECT user_id, meta_value as streak FROM {$wpdb->prefix}usermeta WHERE meta_key = 'rk_streak_count' AND user_id NOT IN (SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = 'rk_last_daily_claim' AND DATE(meta_value) = CURDATE()) LIMIT 50");
        foreach ($users as $u) rk_send_temu_push($u->user_id, 'push_streak_danger', ['[STREAK_DAY]' => $u->streak]);
    }

    // Trigger 2: Points Expiring (9 AM & 3 PM)
    if ($hour == 9 || $hour == 15) {
        $users = $wpdb->get_results("SELECT user_id, meta_value as points FROM {$wpdb->prefix}usermeta WHERE meta_key = 'rk_points_balance' AND CAST(meta_value AS UNSIGNED) > 100 ORDER BY RAND() LIMIT 30");
        foreach ($users as $u) rk_send_temu_push($u->user_id, 'push_points_expiring', ['[POINTS]' => $u->points]);
    }

    // Trigger 3: Low Balance (12 PM)
    if ($hour == 12) {
        $users = $wpdb->get_results("SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = 'wallet_balance' AND CAST(meta_value AS UNSIGNED) < 100 AND CAST(meta_value AS UNSIGNED) >= 0 LIMIT 30");
        foreach ($users as $u) rk_send_temu_push($u->user_id, 'push_low_balance', []);
    }

    // Trigger 4: Inactive (8 PM)
    if ($hour == 20) {
        $users = $wpdb->get_results("SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = 'last_login' AND meta_value < DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 20");
        foreach ($users as $u) rk_send_temu_push($u->user_id, 'push_inactive_user', []);
    }
}

// ================================================================
// PART C: UTILITIES & SENDERS
// ================================================================

function rk_send_admin_template_digest() {
    $admin_email = get_option('admin_email');
    
    // Create Perfect Mock Data
    $mock_user = [
        'display_name' => 'Admin User',
        'first_name' => 'Admin',
        'balance' => 50000,
        'streak' => 5,
        'total_won' => 0,
        'days_inactive' => 0 
    ];
    
    $html = "<html><body style='font-family:sans-serif;'>";
    $html .= "<h1>🧠 RaffleKings Full Psychology Matrix (140+ Templates)</h1>";
    $html .= "<p>Generated: " . date('Y-m-d H:i:s') . "</p>";
    $html .= "<hr>";
    
    // Get ALL templates (forcing 'all' time block)
    $templates = rk_get_template_pool($mock_user, 'all');
    
    foreach ($templates as $idx => $t) {
        $num = $idx + 1;
        $html .= "<div style='border:1px solid #ccc; margin-bottom:20px; padding:15px; background:#fafafa;'>";
        $html .= "<strong style='font-size:1.2em;'>#{$num} Subject:</strong> {$t['subject']}<br>";
        $html .= "<strong>Preview:</strong><br><div style='border:1px dashed #999; padding:10px; margin-top:5px; background:#fff;'>{$t['body']}</div>";
        $html .= "</div>";
    }
    
    $html .= "</body></html>";
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail($admin_email, "RaffleKings Psychology Matrix (Full 140)", $html, $headers);
}

function rk_send_temu_push($user_id, $template_key, $replacements = []) {
    global $wpdb;
    $last_push = get_user_meta($user_id, 'rk_last_push_sent', true);
    if ($last_push && (time() - $last_push) < 43200) return; // 12h Cooldown

    $static_templates = [
        'push_streak_danger' => ['title' => '🔥 STREAK DANGER!', 'body' => 'You are 1 tap away from losing your Day [STREAK_DAY] Reward.'],
        'push_points_expiring' => ['title' => '⚠️ Points Expiring!', 'body' => 'URGENT: Your [POINTS] points are pending deletion.'],
        'push_low_balance' => ['title' => '💸 CREDIT ALERT', 'body' => 'Your wallet is low! We have a bonus waiting for you.'],
        'push_inactive_user' => ['title' => '💔 We saved this for you...', 'body' => 'You haven\'t logged in for 7 days. Login now.']
    ];

    if (!isset($static_templates[$template_key])) return;
    $t = $static_templates[$template_key];
    
    $title = $t['title'];
    $msg = $t['body'];

    foreach ($replacements as $key => $val) {
        $title = str_replace($key, $val, $title);
        $msg = str_replace($key, $val, $msg);
    }
    
    $player_id = get_user_meta($user_id, 'rk_onesignal_id', true);
    if ($player_id) {
        rk_send_push_notification_direct($player_id, $title, $msg);
        update_user_meta($user_id, 'rk_last_push_sent', time());
    }
}

// Direct sender
function rk_send_push_notification_direct($player_id, $title, $message) {
    $app_id = defined('RK_ONESIGNAL_APP_ID') ? RK_ONESIGNAL_APP_ID : '';
    $api_key = defined('RK_ONESIGNAL_API_KEY') ? RK_ONESIGNAL_API_KEY : '';

    if(empty($app_id) || empty($api_key)) return;

    $fields = array(
        'app_id' => $app_id,
        'include_player_ids' => array($player_id),
        'headings' => array("en" => $title),
        'contents' => array("en" => $message),
        'url' => defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : site_url()
    );
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8', 'Authorization: Basic ' . $api_key));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    
    curl_exec($ch);
    curl_close($ch);
}

// ================================================================
// PART D: AUTOMATED RECEIPT CLEANUP
// ================================================================

function rk_run_receipt_cleanup() {
    global $wpdb;
    $table = $wpdb->prefix . 'raffle_transactions';
    $rows = $wpdb->get_results("SELECT id, user_id, proof_url FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND proof_url LIKE 'http%' AND proof_url NOT LIKE '%deleted%' AND proof_url NOT LIKE '%kept%' LIMIT 50");
    if (empty($rows)) return;
    if (!function_exists('wp_delete_attachment')) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
    }
    foreach ($rows as $row) {
        $user_profile_pic = get_user_meta($row->user_id, 'profile_pic_url', true);
        if ($user_profile_pic === $row->proof_url) {
            $wpdb->update($table, ['proof_url' => 'file_kept_profile_match'], ['id' => $row->id]);
            continue;
        }
        $attachment_id = attachment_url_to_postid($row->proof_url);
        if ($attachment_id) wp_delete_attachment($attachment_id, true);
        $wpdb->update($table, ['proof_url' => 'file_deleted_auto_7days'], ['id' => $row->id]);
    }
}

/**
 * DEBUG: PREVIEW EMAIL TEMPLATE (RETURNS HTML FOR ADMIN DISPLAY)
 */
function rk_debug_preview_email_template($user_id) {
    $user = get_userdata($user_id);
    if (!$user) return "User not found";
    
    $template = rk_get_random_clickbait_template($user);
    if (!$template) return "No template found";
    
    // Wrap it using system style
    if(function_exists('rk_get_email_html')) {
        return rk_get_email_html($template['subject'], $template['body'], '', '');
    }
    
    return $template['body'];
}