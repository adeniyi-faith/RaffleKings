<?php
/**
 * Number holds.
 *
 * When a player picks lucky numbers and heads to checkout, those numbers are
 * held for them for a short time (10 minutes) so nobody else can buy them
 * while they sign in and pay. A hold belongs to either a signed-in user or,
 * for a guest, to a random "holder key" that the guest's browser keeps. When
 * the guest signs in, the hold is handed over to their new account.
 *
 * A hold does NOT sell the number. The raffle_entries table (unique on
 * raffle + number) stays the single source of truth for what is sold; holds
 * only stop OTHER people from paying for a number while it is held.
 */

if (!defined('ABSPATH')) {
    exit;
}

const RK_HOLD_SECONDS = 600;            // 10 minutes
const RK_HOLD_PENDING_BANK_SECONDS = 21600; // 6 hours, while a bank receipt awaits review
const RK_HOLD_MAX_PER_REQUEST = 100;
const RK_HOLD_MAX_PER_OWNER = 100;

add_action('init', 'rk_holds_maybe_create_table');
function rk_holds_maybe_create_table() {
    if (get_option('rk_holds_table_version') === '1') return;
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $table = rk_holds_table();
    $charset_collate = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        raffle_id mediumint(9) NOT NULL,
        ticket_number mediumint(9) NOT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        holder_key varchar(64) NOT NULL DEFAULT '',
        expires_at int(10) unsigned NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY raffle_ticket (raffle_id, ticket_number),
        KEY holder_key (holder_key),
        KEY user_id (user_id),
        KEY expires_at (expires_at)
    ) $charset_collate;");
    update_option('rk_holds_table_version', '1');
}

function rk_holds_table() {
    global $wpdb;
    return $wpdb->prefix . 'raffle_number_holds';
}

/** Guest holder keys are random tokens made by the browser; accept only a safe shape. */
function rk_holds_clean_key($key) {
    $key = is_string($key) ? trim($key) : '';
    return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) ? $key : '';
}

/**
 * Turn "5,12, 12,abc,0" (or an array) into a sorted list of unique whole
 * numbers between 1 and $max_pool. Anything else is dropped.
 */
function rk_holds_parse_numbers($raw, $max_pool = 1000) {
    if (is_string($raw)) $raw = explode(',', $raw);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $n) {
        $n = trim((string) $n);
        if ($n === '' || !ctype_digit($n)) continue;
        $n = (int) $n;
        if ($n >= 1 && $n <= $max_pool) $out[$n] = $n;
    }
    $out = array_values($out);
    sort($out);
    return array_slice($out, 0, RK_HOLD_MAX_PER_REQUEST);
}

function rk_holds_raffle_max($raffle_id) {
    $max = (int) get_post_meta($raffle_id, 'max_tickets', true);
    return $max > 0 ? $max : 1000;
}

function rk_holds_int_list($numbers) {
    return implode(',', array_map('intval', $numbers));
}

function rk_holds_purge_expired() {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . rk_holds_table() . ' WHERE expires_at <= %d', time()));
}

/** Numbers (from $numbers) that are already sold in this raffle. */
function rk_holds_sold_numbers($raffle_id, $numbers) {
    global $wpdb;
    if (!$numbers) return [];
    $entries = $wpdb->prefix . 'raffle_entries';
    $list = rk_holds_int_list($numbers);
    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT ticket_number FROM $entries WHERE raffle_id = %d AND ticket_number IN ($list)",
        $raffle_id
    )));
}

/**
 * Numbers (from $numbers) currently held by SOMEONE ELSE.
 * "Someone else" = a live hold whose user is not $user_id and whose key is not $holder_key.
 */
function rk_holds_numbers_held_by_others($raffle_id, $numbers, $user_id, $holder_key) {
    global $wpdb;
    if (!$numbers) return [];
    $list = rk_holds_int_list($numbers);
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT ticket_number, user_id, holder_key FROM ' . rk_holds_table() .
        " WHERE raffle_id = %d AND ticket_number IN ($list) AND expires_at > %d",
        $raffle_id, time()
    ), ARRAY_A);
    $held = [];
    foreach ((array) $rows as $r) {
        $mine = ($user_id && (int) $r['user_id'] === (int) $user_id)
            || ($holder_key !== '' && $r['holder_key'] === $holder_key);
        if (!$mine) $held[] = (int) $r['ticket_number'];
    }
    return $held;
}

/**
 * Hold $numbers for this owner.
 *
 * - Numbers already sold or held by someone else are reported back and NOTHING
 *   is held (all or nothing), so the player can re-pick.
 * - Numbers the owner already holds keep their original deadline (the clock is
 *   not restarted by refreshing the page); once a hold has run out, asking
 *   again gives a fresh 10 minutes if the number is still free.
 * - A guest's hold is handed to the account once they are signed in.
 *
 * @return array { ok, sold[], held[], expires_at, seconds_left }
 */
function rk_holds_claim($raffle_id, $numbers, $user_id, $holder_key) {
    global $wpdb;
    $table = rk_holds_table();
    $now = time();
    $result = ['ok' => false, 'sold' => [], 'held' => [], 'expires_at' => 0, 'seconds_left' => 0];
    if (!$numbers) return $result;

    $wpdb->query('START TRANSACTION');
    try {
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE expires_at <= %d", $now));

        // Hand a guest's holds to the account that just signed in.
        if ($user_id && $holder_key !== '') {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET user_id = %d WHERE holder_key = %s AND user_id = 0",
                $user_id, $holder_key
            ));
        }

        // Lock the rows we care about so two people can't both win the same number.
        $list = rk_holds_int_list($numbers);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ticket_number, user_id, holder_key, expires_at FROM $table
             WHERE raffle_id = %d AND ticket_number IN ($list) FOR UPDATE",
            $raffle_id
        ), ARRAY_A);

        $mine = [];
        foreach ((array) $rows as $r) {
            $is_mine = ($user_id && (int) $r['user_id'] === (int) $user_id)
                || ($holder_key !== '' && $r['holder_key'] === $holder_key);
            if ($is_mine) $mine[(int) $r['ticket_number']] = (int) $r['expires_at'];
            else $result['held'][] = (int) $r['ticket_number'];
        }
        $result['sold'] = rk_holds_sold_numbers($raffle_id, $numbers);

        if ($result['sold'] || $result['held']) {
            $wpdb->query('ROLLBACK');
            return $result;
        }

        // Cap how many numbers one owner can sit on across all raffles.
        $owned_now = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE expires_at > %d AND ((%d > 0 AND user_id = %d) OR (%s <> '' AND holder_key = %s))",
            $now, $user_id, $user_id, $holder_key, $holder_key
        ));
        $new_count = count(array_diff($numbers, array_keys($mine)));
        if ($owned_now + $new_count > RK_HOLD_MAX_PER_OWNER) {
            $wpdb->query('ROLLBACK');
            $result['too_many'] = true;
            return $result;
        }

        $fresh_deadline = $now + RK_HOLD_SECONDS;
        foreach ($numbers as $n) {
            if (isset($mine[$n])) continue;
            $ok = $wpdb->insert($table, [
                'raffle_id' => $raffle_id,
                'ticket_number' => $n,
                'user_id' => $user_id ?: 0,
                'holder_key' => $holder_key,
                'expires_at' => $fresh_deadline,
            ]);
            if ($ok === false) throw new Exception('hold insert failed');
        }
        $wpdb->query('COMMIT');
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('RaffleKings hold claim failed: ' . $e->getMessage());
        return $result;
    }

    // The whole basket runs out when its earliest number does.
    $deadline = $fresh_deadline;
    foreach ($mine as $exp) $deadline = min($deadline, $exp);
    $result['ok'] = true;
    $result['expires_at'] = $deadline;
    $result['seconds_left'] = max(0, $deadline - $now);
    return $result;
}

/** Give up numbers the owner is holding (e.g. they chose different ones). */
function rk_holds_release($raffle_id, $numbers, $user_id, $holder_key) {
    global $wpdb;
    if (!$numbers) return;
    $list = rk_holds_int_list($numbers);
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . rk_holds_table() . " WHERE raffle_id = %d AND ticket_number IN ($list)
         AND ((%d > 0 AND user_id = %d) OR (%s <> '' AND holder_key = %s))",
        $raffle_id, $user_id, $user_id, $holder_key, $holder_key
    ));
}

/**
 * Called by the payment handler before any money moves: refuse if any of the
 * numbers is held by someone else. Covers wallet, earnings and bank transfer.
 * A payment for numbers nobody holds still goes through, as before.
 */
function rk_holds_check_for_payment($raffle_id, $numbers_str, $user_id) {
    $numbers = rk_holds_parse_numbers($numbers_str, PHP_INT_MAX);
    if (!$numbers) return true;
    // The signed-in owner may still have holds under their guest key; the
    // checkout page already handed those over, so user_id is enough here.
    $held = rk_holds_numbers_held_by_others((int) $raffle_id, $numbers, (int) $user_id, '');
    if ($held) {
        return new WP_Error(
            'numbers_held',
            'Number' . (count($held) > 1 ? 's ' : ' ') . implode(', ', $held) . (count($held) > 1 ? ' are' : ' is') . ' being held by another player right now. Please pick different numbers.',
            ['status' => 409]
        );
    }
    return true;
}

/** While a bank receipt waits for review, keep the payer's numbers safe for longer. */
function rk_holds_extend_for_pending_bank($raffle_id, $numbers_str, $user_id) {
    global $wpdb;
    $numbers = rk_holds_parse_numbers($numbers_str, PHP_INT_MAX);
    if (!$numbers || !$user_id) return;
    $list = rk_holds_int_list($numbers);
    $wpdb->query($wpdb->prepare(
        'UPDATE ' . rk_holds_table() . " SET expires_at = %d
         WHERE raffle_id = %d AND user_id = %d AND ticket_number IN ($list)",
        time() + RK_HOLD_PENDING_BANK_SECONDS, $raffle_id, $user_id
    ));
}

// ---------------------------------------------------------------------------
// Handlers for ajax-router.php (public: guests use them too)
// ---------------------------------------------------------------------------

function rk_holds_request_context() {
    global $body_data;
    $raffle_id = (int) ($body_data['raffle_id'] ?? 0);
    $post = $raffle_id ? get_post($raffle_id) : null;
    if (!$post || $post->post_type !== 'raffle' || $post->post_status !== 'publish') {
        return new WP_Error('raffle_not_found', 'Raffle not found.', ['status' => 404]);
    }
    return [
        'raffle_id' => $raffle_id,
        'user_id' => (int) get_current_user_id(),
        'holder_key' => rk_holds_clean_key($body_data['holder_key'] ?? ''),
        'numbers' => rk_holds_parse_numbers($body_data['numbers'] ?? '', rk_holds_raffle_max($raffle_id)),
    ];
}

function rk_holds_handle_hold() {
    if (function_exists('rk_check_rate_limit')) {
        $limit = rk_check_rate_limit('hold_numbers', 30, 60);
        if (is_wp_error($limit)) return $limit;
    }
    $ctx = rk_holds_request_context();
    if (is_wp_error($ctx)) return $ctx;
    if (!$ctx['user_id'] && $ctx['holder_key'] === '') {
        return new WP_Error('missing_holder', 'Could not save your numbers. Please refresh and try again.', ['status' => 400]);
    }
    if (!$ctx['numbers']) {
        return new WP_Error('no_numbers', 'Pick at least one valid number.', ['status' => 400]);
    }

    nocache_headers();
    $r = rk_holds_claim($ctx['raffle_id'], $ctx['numbers'], $ctx['user_id'], $ctx['holder_key']);
    if (!$r['ok']) {
        $bad = array_values(array_unique(array_merge($r['sold'], $r['held'])));
        sort($bad);
        if (!empty($r['too_many'])) {
            return ['success' => false, 'code' => 'too_many_holds', 'message' => 'You are holding too many numbers at once.'];
        }
        return [
            'success' => false,
            'code' => 'numbers_unavailable',
            'message' => $bad ? 'Some of your numbers were just taken.' : 'Could not hold your numbers. Please try again.',
            'unavailable' => $bad,
            'sold' => $r['sold'],
            'held' => $r['held'],
        ];
    }
    return [
        'success' => true,
        'message' => 'Numbers held.',
        'numbers' => $ctx['numbers'],
        'expires_at' => $r['expires_at'],
        'seconds_left' => $r['seconds_left'],
    ];
}

function rk_holds_handle_release() {
    $ctx = rk_holds_request_context();
    if (is_wp_error($ctx)) return $ctx;
    nocache_headers();
    rk_holds_release($ctx['raffle_id'], $ctx['numbers'], $ctx['user_id'], $ctx['holder_key']);
    return ['success' => true, 'message' => 'Released.'];
}

/** Which numbers are sold, and which are held by other people (for the number picker). */
function rk_holds_handle_status() {
    $ctx = rk_holds_request_context();
    if (is_wp_error($ctx)) return $ctx;
    global $wpdb;
    nocache_headers();
    rk_holds_purge_expired();

    $entries = $wpdb->prefix . 'raffle_entries';
    $sold = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT ticket_number FROM $entries WHERE raffle_id = %d", $ctx['raffle_id']
    )));

    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT ticket_number, user_id, holder_key FROM ' . rk_holds_table() . ' WHERE raffle_id = %d AND expires_at > %d',
        $ctx['raffle_id'], time()
    ), ARRAY_A);
    $held_by_others = [];
    foreach ((array) $rows as $r) {
        $mine = ($ctx['user_id'] && (int) $r['user_id'] === $ctx['user_id'])
            || ($ctx['holder_key'] !== '' && $r['holder_key'] === $ctx['holder_key']);
        if (!$mine) $held_by_others[] = (int) $r['ticket_number'];
    }
    return ['success' => true, 'sold' => $sold, 'held_by_others' => $held_by_others];
}
