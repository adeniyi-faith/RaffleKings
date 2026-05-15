// --- GLOBAL APP CONFIGURATION ---

// 1. BACKEND URL (WordPress API)
const WORDPRESS_URL = "https://api.rafflekings.com.ng"; 

// 2. FRONTEND URL (For generating referral links)
const FRONTEND_URL = "https://rafflekings.com.ng";

// 3. API ENDPOINTS
const API_CONFIG = {
    REGISTER:   `${WORDPRESS_URL}/wp-json/lottery/v1/register`,
    LOGIN:      `${WORDPRESS_URL}/wp-json/jwt-auth/v1/token`,
    USER_ME:    `${WORDPRESS_URL}/wp-json/wp/v2/users/me`,
    
    // Auth & Password Reset
    FORGOT_PASSWORD: `${WORDPRESS_URL}/wp-json/raffle/v1/auth/forgot-password`,
    RESET_PASSWORD:  `${WORDPRESS_URL}/wp-json/raffle/v1/auth/reset-password`,
    
    // Raffle System Endpoints
    PAYMENT:    `${WORDPRESS_URL}/wp-json/raffle/v1/payment`,
    BALANCE:    `${WORDPRESS_URL}/wp-json/raffle/v1/balance`,
    SETTINGS:   `${WORDPRESS_URL}/wp-json/raffle/v1/settings`,
    TRANSFER:   `${WORDPRESS_URL}/wp-json/raffle/v1/transfer`,
    
    // Core Profile & Logic
    PROFILE:        `${WORDPRESS_URL}/wp-json/raffle/v1/profile`,
    TICKETS:        `${WORDPRESS_URL}/wp-json/raffle/v1/tickets`,
    CLAIM_DAILY:    `${WORDPRESS_URL}/wp-json/raffle/v1/claim-daily`,
    CLAIM_TASK:     `${WORDPRESS_URL}/wp-json/raffle/v1/claim-task`,
    REDEEM:         `${WORDPRESS_URL}/wp-json/raffle/v1/redeem-points`,
    SAVE_DEVICE:    `${WORDPRESS_URL}/wp-json/raffle/v1/save-device`,
    PROFILE_UPDATE: `${WORDPRESS_URL}/wp-json/raffle/v1/profile`,
    SPIN_WHEEL:     `${WORDPRESS_URL}/wp-json/raffle/v1/spin-wheel`,
    
    // *** NEW: Tutorials ***
    TUTORIALS:       `${WORDPRESS_URL}/wp-json/raffle/v1/tutorials`,
    TUTORIAL_ACTION: `${WORDPRESS_URL}/wp-json/raffle/v1/tutorials/helpful`,

    // --- SITE ALERTS (Required for Header Notifications) ---
    SITE_NOTICES:    `${WORDPRESS_URL}/wp-json/raffle/v1/site-notices`,

    // --- REFERRAL & HALL OF FAME ---
    REFERRAL_STATS: `${WORDPRESS_URL}/wp-json/raffle/v1/referral-stats`,
    HALL_OF_FAME:   `${WORDPRESS_URL}/wp-json/raffle/v1/hall-of-fame`,
    
    // Cart & Finance
    CART_SYNC:     `${WORDPRESS_URL}/wp-json/raffle/v1/cart/sync`,
    BANK_ACCOUNTS: `${WORDPRESS_URL}/wp-json/raffle/v1/bank-accounts`,
    WITHDRAW:      `${WORDPRESS_URL}/wp-json/raffle/v1/withdraw`,
    TRANSACTIONS:  `${WORDPRESS_URL}/wp-json/raffle/v1/transactions`,
    REWARDS_STATE: `${WORDPRESS_URL}/wp-json/raffle/v1/rewards-state`,

    // Live Draw & Chat Endpoints
    DRAW_RESULTS:   `${WORDPRESS_URL}/wp-json/raffle/v1/draw/results`,
    LIVE_COMMENT:   `${WORDPRESS_URL}/wp-json/raffle/v1/live/comment`,
    LIVE_COMMENTS:  `${WORDPRESS_URL}/wp-json/raffle/v1/live/comments`,

    // System Health
    SYSTEM_LOG:     `${WORDPRESS_URL}/wp-json/raffle/v1/system/log`
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