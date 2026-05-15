<?php
/*
Plugin Name: RaffleKings Core Logic
Description: Essential backend logic, CPTs, crons, and custom APIs for the RK ecosystem.
Version: 1.0.0
Author: RK Dev Team
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define a constant for easy path referencing
define('RK_CORE_DIR', WPMU_PLUGIN_DIR . '/rk-core');

// 1. Load configuration and system utilities first
require_once RK_CORE_DIR . '/theme-config.php';
require_once RK_CORE_DIR . '/api-system.php';
require_once RK_CORE_DIR . '/database.php';

// 2. Load Authentication and User management
require_once RK_CORE_DIR . '/api-auth.php';

// 3. Load Financial and Gamification logic
require_once RK_CORE_DIR . '/api-financials.php';
require_once RK_CORE_DIR . '/api-gamification.php';

// 4. Load Background tasks and Admin UI
require_once RK_CORE_DIR . '/cron-system.php';
require_once RK_CORE_DIR . '/admin-panel.php';

// 5. Load Endpoints (Temporarily kept to ensure backward compatibility during migration)
require_once RK_CORE_DIR . '/api-endpoints.php';