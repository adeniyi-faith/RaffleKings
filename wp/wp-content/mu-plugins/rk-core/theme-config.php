<?php
/**
 * Theme Setup, Constants, and Security
 * Extracted from functions.php
 * * SECURITY FINALIZED: Actual keys removed. Relies 100% on wp-config.php.
 */

// 1. GLOBAL CONSTANTS
// If these are missing from wp-config.php, the features will simply be disabled.
if (!defined('RK_GEMINI_KEY')) {
    define('RK_GEMINI_KEY', ''); // Secure: No key exposed here
}

// --- TELEGRAM NOTIFICATION CONFIGURATION ---
if (!defined('RK_TELEGRAM_BOT_TOKEN')) {
    define('RK_TELEGRAM_BOT_TOKEN', ''); // Secure: No token exposed here
}
if (!defined('RK_TELEGRAM_ADMIN_IDS')) {
    define('RK_TELEGRAM_ADMIN_IDS', ''); // Secure: No IDs exposed here
}

// --- AUTOMATED BONUS SYSTEM CONFIGURATION ---
if (!defined('RK_DAILY_RETENTION_BUDGET_PERCENT')) {
    define('RK_DAILY_RETENTION_BUDGET_PERCENT', 0.20); // 20% of daily revenue
}
define('RK_AMBASSADOR_RATIO_PERCENT', 0.04);       // 4% of daily active users
define('RK_AMBASSADOR_ROTATION_DAYS', 30);         // Cooldown for ambassadors
define('RK_MIN_WITHDRAWAL_LIMIT', 2000);           // The target threshold (₦)
define('RK_RETENTION_WIN_PROBABILITY', 35);        // 35% chance for regular users (1-100)

// *** NEW: SECURITY DEPOSIT THRESHOLD ***
define('RK_MIN_LIFETIME_DEPOSIT', 1000);           // User must have deposited this amount LIFETIME to withdraw

// *** CASHBACK BONUS CONFIGURATION ***
define('RK_DEPOSIT_BONUS_PERCENT', 0.00);          // 0% Bonus on Top-ups (Temporarily Disabled)

// ==========================================
// *** NEW: EMAIL LIMIT CONTROLLER ***
// ==========================================
// CHANGE THIS NUMBER WHEN YOU UPGRADE BREVO
// Set to 300 for Free Plan. Set to 1000000 for Unlimited.
if (!defined('RK_EMAIL_DAILY_LIMIT')) {
    define('RK_EMAIL_DAILY_LIMIT', 300); 
}

// 2. STANDARD THEME SETUP
if ( ! function_exists( 'twentytwentyfive_post_format_setup' ) ) :
	function twentytwentyfive_post_format_setup() {
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_post_format_setup' );

if ( ! function_exists( 'twentytwentyfive_editor_style' ) ) :
	function twentytwentyfive_editor_style() {
		add_editor_style( 'assets/css/editor-style.css' );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_editor_style' );

if ( ! function_exists( 'twentytwentyfive_enqueue_styles' ) ) :
	function twentytwentyfive_enqueue_styles() {
		$suffix = SCRIPT_DEBUG ? '' : '.min';
		$src    = 'style' . $suffix . '.css';
		wp_enqueue_style( 'twentytwentyfive-style', get_parent_theme_file_uri( $src ), array(), wp_get_theme()->get( 'Version' ) );
		wp_style_add_data( 'twentytwentyfive-style', 'path', get_parent_theme_file_path( $src ) );

        // *** Enqueue Watchdog Script ***
        wp_enqueue_script('rk-watchdog', get_template_directory_uri() . '/assets/js/watchdog.js', [], '1.0', false); 
        
        wp_localize_script('rk-watchdog', 'rk_vars', [
            'user_id' => get_current_user_id()
        ]);
	}
endif;
add_action( 'wp_enqueue_scripts', 'twentytwentyfive_enqueue_styles' );

// 3. SECURITY & CORS (STRICT MODE)
// Only allow requests from the official domain
add_action('send_headers', function() {
    $allowed_origins = ['https://rafflekings.com.ng', 'https://www.rafflekings.com.ng'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
        header("Access-Control-Allow-Credentials: true");
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        status_header(200); exit();
    }
});

// 4. ADMIN REDIRECTS (Raffle Ops Logic)
add_action('load-index.php', function() {
    if(current_user_can('manage_options')) {
        wp_redirect(admin_url('admin.php?page=raffle-ops'));
        exit;
    }
});

add_action('login_redirect', function($redirect_to, $request, $user) {
    if (isset($user->roles) && is_array($user->roles)) {
        if (in_array('administrator', $user->roles)) {
            return admin_url('admin.php?page=raffle-ops');
        }
    }
    return $redirect_to;
}, 10, 3);

// 5. CUSTOM LOGIN PAGE STYLING
function rk_custom_login_style() {
    echo '<style type="text/css">
        body.login {
            background-color: #f3f4f6 !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
        .login h1 a {
            background-image: none !important;
            width: 100% !important;
            height: auto !important;
            text-indent: 0 !important;
            color: #111827 !important;
            font-size: 28px !important;
            font-weight: 900 !important;
            text-transform: uppercase !important;
            margin-bottom: 20px !important;
            padding-bottom: 10px;
        }
        .login h1 a::after {
            content: "👑 Raffle Kings";
            display: block;
            text-align: center;
            letter-spacing: -0.5px;
        }
        .login form {
            background: #ffffff !important;
            border: none !important;
            border-radius: 16px !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
            padding: 40px !important;
            max-width: 400px;
            margin-top: 0 !important;
        }
        .login #login {
            padding: 0 !important;
            width: 100%;
            max-width: 420px;
        }
        .login label {
            color: #374151 !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            margin-bottom: 6px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px;
        }
        .login form .input, .login form input[type=checkbox], .login input[type=text] {
            border: 2px solid #e5e7eb !important;
            border-radius: 10px !important;
            background: #f9fafb !important;
            font-size: 16px !important;
            padding: 12px 15px !important;
            color: #111827 !important;
            box-shadow: none !important;
            margin-bottom: 20px !important;
            height: 50px !important;
        }
        .login form .input:focus {
            border-color: #2563EB !important;
            background: #fff !important;
            outline: none !important;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1) !important;
        }
        .wp-core-ui .button-primary {
            background: #2563EB !important;
            border: none !important;
            border-radius: 10px !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3) !important;
            text-shadow: none !important;
            font-weight: 800 !important;
            width: 100% !important;
            margin-top: 10px !important;
            height: 50px !important;
            font-size: 16px !important;
            transition: all 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .wp-core-ui .button-primary:hover {
            background: #1d4ed8 !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(37, 99, 235, 0.4) !important;
        }
        .login .forgetmenot {
            float: none !important;
            margin-bottom: 20px !important;
            display: flex;
            align-items: center;
        }
        .login #nav, .login #backtoblog, .language-switcher {
            display: none !important;
        }
    </style>';
}
add_action('login_enqueue_scripts', 'rk_custom_login_style');

// Change logo URL to homepage
add_filter('login_headerurl', function() {
    return home_url();
});

// Change logo title attribute
add_filter('login_headertext', function() {
    return 'Raffle Kings Admin';
});

/**
 * 6. SECURE COOKIE SETTINGS
 * Fixes Scanner "HttpOnly/Secure flag" warning
 */
add_action('send_headers', function() {
    if (!is_admin()) {
        // DISABLED: CSP is now handled entirely by .htaccess to avoid double-header conflicts
        
        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
    }
});
?>