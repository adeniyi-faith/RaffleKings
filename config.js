// --- GLOBAL APP CONFIGURATION ---

// Phase 1: same-origin local API gateway.
const WORDPRESS_URL = "";
const AJAX_ROUTER = "ajax-router.php";
const ajaxAction = (action) => `${AJAX_ROUTER}?action=${encodeURIComponent(action)}`;

// FRONTEND URL (For generating referral links)
const FRONTEND_URL = "https://rafflekings.com.ng";

// API ENDPOINTS
const API_CONFIG = {
    REGISTER: ajaxAction('register'),
    LOGIN: ajaxAction('login'),
    USER_ME: ajaxAction('get_profile'),

    // Auth & Password Reset
    FORGOT_PASSWORD: ajaxAction('forgot_password'),
    RESET_PASSWORD: ajaxAction('reset_password'),

    // Raffle System Endpoints
    PAYMENT: ajaxAction('payment'),
    BALANCE: ajaxAction('get_balance'),
    SETTINGS: ajaxAction('get_settings'),
    TRANSFER: ajaxAction('transfer'),
    RAFFLES: ajaxAction('get_raffles'),
    RAFFLE: ajaxAction('get_raffle'),

    // Core Profile & Logic
    PROFILE: ajaxAction('get_profile'),
    TICKETS: ajaxAction('user_tickets'),
    CLAIM_DAILY: ajaxAction('daily_claim'),
    CLAIM_TASK: ajaxAction('task_claim'),
    REDEEM: ajaxAction('redeem_points'),
    SAVE_DEVICE: ajaxAction('push_device_save'),
    PROFILE_UPDATE: ajaxAction('update_profile'),
    SPIN_WHEEL: ajaxAction('spin'),

    // Tutorials
    TUTORIALS: ajaxAction('tutorials'),
    TUTORIAL_ACTION: ajaxAction('tutorial_helpful'),

    // Site alerts
    SITE_NOTICES: ajaxAction('site_notices'),

    // Referral & Hall of Fame
    REFERRAL_STATS: ajaxAction('referral_stats'),
    HALL_OF_FAME: ajaxAction('hall_of_fame'),

    // Cart & Finance
    CART_SYNC: ajaxAction('cart_sync'),
    BANK_ACCOUNTS: ajaxAction('bank_accounts'),
    SAVE_BANK_ACCOUNT: ajaxAction('save_bank_account'),
    DELETE_BANK_ACCOUNT: ajaxAction('delete_bank_account'),
    WITHDRAW: ajaxAction('withdrawal'),
    TRANSACTIONS: ajaxAction('transactions'),
    REWARDS_STATE: ajaxAction('rewards_state'),

    // Live Draw & Chat Endpoints
    DRAW_RESULTS: ajaxAction('draw_results'),
    LIVE_COMMENT: ajaxAction('post_live_comment'),
    LIVE_COMMENTS: ajaxAction('live_comments'),

    // System Health
    SYSTEM_LOG: ajaxAction('system_log')
};

// APP SETTINGS
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

async function rkApiJson(response) {
    const payload = await response.json();
    if (payload && Object.prototype.hasOwnProperty.call(payload, 'data')) {
        return payload.data;
    }
    return payload;
}
