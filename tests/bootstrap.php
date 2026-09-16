<?php
/**
 * Phase 0 item 4: the live PHP app had zero tests and deployed with a raw
 * `git pull` straight to production. This is the first small test harness —
 * it loads the mu-plugin source files in isolation (no live WordPress/MySQL
 * needed) by stubbing the couple of WP functions they call at parse time,
 * so pure business logic (pricing, etc.) can be tested directly in CI.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('add_action')) {
    function add_action(...$args) {}
}
if (!function_exists('add_filter')) {
    function add_filter(...$args) {}
}

require_once __DIR__ . '/../wp/wp-content/mu-plugins/rk-core/api-financials.php';
