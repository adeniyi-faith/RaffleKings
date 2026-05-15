<?php
define('RK_FRONTEND_APP', true);
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/wp/wp-load.php');

$endpoints = [
    'rk_handle_new_registration',
    'rk_handle_forgot_password',
    'rk_handle_reset_password',
    'rk_handle_profile_request',
    'rk_get_balance',
    'rk_handle_payment_ai',
    'rk_handle_transfer',
    'rk_handle_withdrawal',
    'rk_get_bank_accounts',
    'rk_save_bank_account',
    'rk_delete_bank_account',
    'rk_get_user_transactions',
    'rk_handle_cart_sync',
    'rk_get_user_tickets',
    'rk_handle_daily_claim',
    'rk_get_rewards_state',
    'rk_handle_task_claim',
    'rk_get_referral_stats',
    'rk_execute_spin_logic',
    'rk_handle_redeem_points',
    'rk_save_push_device',
    'rk_get_tutorials',
    'rk_tutorial_mark_helpful',
    'rk_get_site_notices',
    'rk_get_hall_of_fame',
    'rk_get_draw_results',
    'rk_get_live_comments',
    'rk_post_live_comment',
    'rk_handle_system_log',
];

foreach ($endpoints as $func) {
    echo $func . ": " . (function_exists($func) ? 'Yes' : 'No') . "\n";
}
