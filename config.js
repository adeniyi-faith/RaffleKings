// --- GLOBAL APP CONFIGURATION ---

// 1. BACKEND URL (WordPress API)
const WORDPRESS_URL = "";

// 2. FRONTEND URL (For generating referral links)
const FRONTEND_URL = "https://rafflekings.com.ng";

// 3. API ENDPOINTS
const API_CONFIG = {
    REGISTER:   `ajax-router.php?action=register`,
    LOGIN:      `ajax-router.php?action=login`,
    USER_ME:    `ajax-router.php?action=get_profile`,

    // Auth & Password Reset
    FORGOT_PASSWORD: `ajax-router.php?action=forgot_password`,
    RESET_PASSWORD:  `ajax-router.php?action=reset_password`,

    // Raffle System Endpoints
    PAYMENT:    `ajax-router.php?action=process_deposit`,
    BALANCE:    `ajax-router.php?action=get_balances`,
    SETTINGS:   `ajax-router.php?action=get_settings`,
    TRANSFER:   `ajax-router.php?action=transfer`,

    // Core Profile & Logic
    PROFILE:        `ajax-router.php?action=get_profile`,
    TICKETS:        `ajax-router.php?action=get_tickets`,
    CLAIM_DAILY:    `ajax-router.php?action=claim_daily`,
    CLAIM_TASK:     `ajax-router.php?action=claim_task`,
    REDEEM:         `ajax-router.php?action=redeem_points`,
    SAVE_DEVICE:    `ajax-router.php?action=save_device`,
    PROFILE_UPDATE: `ajax-router.php?action=get_profile`,
    SPIN_WHEEL:     `ajax-router.php?action=spin_wheel`,

    // *** NEW: Tutorials ***
    TUTORIALS:       `ajax-router.php?action=get_tutorials`,
    TUTORIAL_ACTION: `ajax-router.php?action=tutorial_helpful`,

    // --- SITE ALERTS (Required for Header Notifications) ---
    SITE_NOTICES:    `ajax-router.php?action=get_site_notices`,

    // --- REFERRAL & HALL OF FAME ---
    REFERRAL_STATS: `ajax-router.php?action=referral_stats`,
    HALL_OF_FAME:   `ajax-router.php?action=get_hall_of_fame`,

    // Cart & Finance
    CART_SYNC:     `ajax-router.php?action=cart_sync`,
    BANK_ACCOUNTS: `ajax-router.php?action=bank_accounts`,
    WITHDRAW:      `ajax-router.php?action=process_withdrawal`,
    TRANSACTIONS:  `ajax-router.php?action=transactions`,
    REWARDS_STATE: `ajax-router.php?action=rewards_state`,

    // Live Draw & Chat Endpoints
    DRAW_RESULTS:   `ajax-router.php?action=draw_results`,
    LIVE_COMMENT:   `ajax-router.php?action=post_live_comment`,
    LIVE_COMMENTS:  `ajax-router.php?action=live_comments`,

    // System Health
    SYSTEM_LOG:     `ajax-router.php?action=system_log`
};

// 3. APP SETTINGS
const APP_SETTINGS = {
    CURRENCY_SYMBOL: '₦',
    POINTS_RATIO: 10,
    SUPPORT_EMAIL: 'help@rafflekings.com.ng',
    DEBUG_MODE: true
};

// --- UTILITY: AUTOMATIC REFERRAL TRACKING ---
(function checkReferralParam() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const refCode = urlParams.get('ref');
        if (refCode) {
            localStorage.setItem('rk_referrer_code', refCode);
            if(APP_SETTINGS.DEBUG_MODE) console.log("Referral Code Captured:", refCode);
        }
    } catch (e) { console.warn("Referral tracking error", e); }
})();

function getStoredReferralCode() { return localStorage.getItem('rk_referrer_code') || ''; }