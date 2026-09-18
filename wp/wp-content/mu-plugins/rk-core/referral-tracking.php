<?php
/**
 * Module: Referral Tracking
 *
 * Fixes two of the referral engine's real accuracy bugs:
 *
 *  1. "Clicks" was a fake stat — rk_referral_clicks usermeta existed but
 *     nothing anywhere ever wrote to it, so every user's referral page
 *     permanently showed 0. rk_track_referral_visit() below is the first
 *     code in this codebase that actually records a click, into the new
 *     `referral_clicks` table (one row per referrer+visitor pair, so
 *     reloading the link or browsing more pages doesn't inflate the count).
 *
 *  2. Referral attribution lived ONLY in the visitor's localStorage, set
 *     by config.js's checkReferralParam(). That's invisible to the server
 *     and lost the moment a visitor switches browser/device, uses a
 *     private window, or has an ad/privacy blocker in the way — with no
 *     error, the referral is just silently never captured. This module
 *     also sets a real first-party cookie (rk_ref_code) server-side, and
 *     rk_handle_new_registration() (api-auth.php) now falls back to it
 *     whenever the submitted form field is empty. This is first-touch:
 *     once a visitor's browser already carries a referral cookie, a later
 *     visit through a different referral link does NOT overwrite it — the
 *     person who actually brought them keeps the credit.
 */

if (!defined('ABSPATH')) {
    exit;
}

const RK_REF_COOKIE = 'rk_ref_code';
const RK_VISITOR_COOKIE = 'rk_vid';
const RK_REF_COOKIE_TTL = 30 * 24 * 60 * 60; // 30 days
const RK_VISITOR_COOKIE_TTL = 365 * 24 * 60 * 60; // 1 year

/**
 * A long-lived random id identifying this browser, independent of the
 * referral itself — used only to de-duplicate click counts (one click per
 * visitor per referrer, not one per pageview). Not a tracking identity;
 * never sent anywhere off-site.
 */
function rk_get_or_set_visitor_id() {
    if (!empty($_COOKIE[RK_VISITOR_COOKIE]) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE[RK_VISITOR_COOKIE])) {
        return $_COOKIE[RK_VISITOR_COOKIE];
    }

    $id = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        setcookie(RK_VISITOR_COOKIE, $id, [
            'expires' => time() + RK_VISITOR_COOKIE_TTL,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[RK_VISITOR_COOKIE] = $id;

    return $id;
}

/**
 * Records a referral visit: resolves the code to a referrer, logs a
 * de-duplicated click, and — first-touch only — sets the cookie
 * rk_handle_new_registration() reads if the eventual signup's form field
 * comes back empty (JS failed, localStorage was cleared, whatever).
 *
 * Safe to call on every pageview that carries a `?ref=` param; harmless
 * (and a no-op) if the code doesn't resolve to anyone.
 */
function rk_track_referral_visit($code) {
    global $wpdb;

    $referrer = rk_find_referrer_by_code($code);
    if (!$referrer) {
        return ['tracked' => false];
    }

    if (empty($_COOKIE[RK_REF_COOKIE]) && !headers_sent()) {
        setcookie(RK_REF_COOKIE, sanitize_text_field($code), [
            'expires' => time() + RK_REF_COOKIE_TTL,
            'path' => '/',
            'httponly' => false, // register.php's own JS prefill logic reads this today
            'samesite' => 'Lax',
        ]);
    }

    $visitor_id = rk_get_or_set_visitor_id();

    $wpdb->query($wpdb->prepare(
        'INSERT IGNORE INTO referral_clicks (referrer_user_id, visitor_token, created_at, updated_at)
         VALUES (%d, %s, %s, %s)',
        $referrer->ID, $visitor_id, current_time('mysql'), current_time('mysql')
    ));

    return ['tracked' => true];
}
