<?php
/**
 * Module: Draw Engine Bridge (OVERHAUL_CHECKLIST.md Phase 3 item 35, part 1 of 4)
 *
 * The legacy draw (rk_run_raffle_draw() in api-gamification.php) picks
 * winners with PHP's ordinary, non-cryptographic shuffle() and shows a
 * "verification hash" that's actually just a hash of already-public
 * fields with a hardcoded salt — forgeable by anyone, not real proof
 * (audit TD-09/TD-10). Phase 1 item 14 already built a real fix,
 * App\Services\ProvablyFairDrawService: a standard commit/reveal scheme
 * (a server seed is committed — hash only — before the pool of eligible
 * tickets even exists, a client seed is derived from that pool itself,
 * and a deterministic HMAC-based Fisher-Yates shuffle combines both) —
 * but nothing WordPress-side ever called it, so every real draw run
 * through the wp-admin "Draw Control Center" still used the old
 * shuffle().
 *
 * This file is a faithful, line-for-line PHP port of that service's
 * algorithm (see runDraw()/verify()/deterministicShuffle()/assignPrizes()
 * in raffle-api/app/Services/ProvablyFairDrawService.php — keep the two
 * in sync if either ever changes), so legacy PHP can run the SAME
 * provably-fair draw directly against the shared database — no HTTP
 * call into Laravel needed, same shared-MySQL architecture item 33's
 * wallet-bridge.php and item 32's shadow-comparison table already use.
 * Winners are written into the SAME wp_raffle_winners table either
 * engine uses, so Hall of Fame/admin winner tools never need to know or
 * care which one ran. The commit/reveal record itself goes into the
 * native `raffle_draws` table Laravel already owns (item 14's
 * migration) — unprefixed, like every other Laravel-owned table this
 * migration writes into directly.
 *
 * Gated behind rk_draw_engine_unified_enabled() (a WP option, default
 * off) for the same instant, zero-deploy rollback item 33 established:
 * flip it off and every draw goes back to the untouched legacy
 * shuffle() path.
 *
 * PREREQUISITE: a draw can only be committed/run through this bridge for
 * a raffle that has already been imported into the native `raffles` /
 * `raffle_prize_tiers` tables (`php artisan legacy:import-raffles`) —
 * `raffle_draws.raffle_id` is a real foreign key into `raffles.id`, not
 * the legacy WordPress post id. If a raffle hasn't been imported yet,
 * this bridge refuses to run rather than silently falling back to the
 * old shuffle() (that would defeat the point of a flag people expect to
 * mean "the new engine is running") — the admin sees a clear error
 * telling them to run the import command first.
 */

if (!defined('ABSPATH')) {
    exit;
}

function rk_draw_engine_unified_enabled() {
    return get_option('rk_draw_engine_unified_enabled', '0') === '1';
}

/** @return int|null The native `raffles.id` for this legacy post id, or null if never imported. */
function rk_draw_bridge_resolve_raffle($legacy_raffle_id) {
    global $wpdb;

    $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM raffles WHERE legacy_post_id = %d', $legacy_raffle_id));

    return $id !== null ? (int) $id : null;
}

/**
 * A deterministic Fisher-Yates shuffle: every swap decision comes from
 * HMAC-SHA256(serverSeed:clientSeed:i), never PHP's own random state —
 * an exact port of ProvablyFairDrawService::deterministicShuffle().
 *
 * @param  array<int, array{uid:int, ticket:int}>  $pool
 */
function rk_draw_bridge_deterministic_shuffle($pool, $server_seed, $client_seed) {
    for ($i = count($pool) - 1; $i > 0; $i--) {
        $digest = hash_hmac('sha256', "{$server_seed}:{$client_seed}:{$i}", $server_seed);
        $j = hexdec(substr($digest, 0, 8)) % ($i + 1);

        [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
    }

    return $pool;
}

/**
 * Walks prize tiers in rank order, awarding each tier's winner_count
 * slots to the next unique users in the shuffled pool — an exact port
 * of ProvablyFairDrawService::assignPrizes().
 *
 * @param  array<int, array{uid:int, ticket:int}>  $shuffled_pool
 * @param  array<int, array{tier_name:string, prize_description:?string, cash_value:float, winner_count:int}>  $prize_tiers
 */
function rk_draw_bridge_assign_prizes($legacy_raffle_id, $shuffled_pool, $prize_tiers) {
    $pool = $shuffled_pool;
    $session_winners = [];
    $winners = [];
    $rank = 1;

    foreach ($prize_tiers as $tier) {
        for ($slot = 0; $slot < (int) $tier['winner_count']; $slot++) {
            $index = null;

            foreach ($pool as $i => $entry) {
                if (!in_array($entry['uid'], $session_winners, true)) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                break 2; // no more unique eligible users left
            }

            $entry = $pool[$index];
            array_splice($pool, $index, 1);
            $session_winners[] = $entry['uid'];

            $display_name = $tier['prize_description']
                ? "{$tier['tier_name']}: {$tier['prize_description']}"
                : $tier['tier_name'];

            $winners[] = [
                'raffle_id' => $legacy_raffle_id,
                'user_id' => $entry['uid'],
                'ticket_number' => $entry['ticket'],
                'prize_name' => $display_name,
                'prize_rank' => $rank,
                'prize_cash_value' => $tier['cash_value'],
            ];

            $rank++;
        }
    }

    return $winners;
}

/**
 * The eligible pool, in a stable order (by entry id) — same rules as
 * both the legacy draw and ProvablyFairDrawService::eligiblePool():
 * only tickets funded by a verified_final transaction, excluding anyone
 * who won any raffle in the last 3 days.
 */
function rk_draw_bridge_eligible_pool($legacy_raffle_id) {
    global $wpdb;
    $entries_table = $wpdb->prefix . 'raffle_entries';
    $txn_table = $wpdb->prefix . 'raffle_transactions';
    $winners_table = $wpdb->prefix . 'raffle_winners';

    $excluded_users = $wpdb->get_col(
        "SELECT DISTINCT user_id FROM $winners_table WHERE won_at > DATE_SUB(NOW(), INTERVAL 3 DAY)"
    );

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT e.id, e.user_id, e.ticket_number
        FROM $entries_table e
        JOIN $txn_table t ON e.txn_id = t.id
        WHERE e.raffle_id = %d AND t.status = 'verified_final'
        ORDER BY e.id ASC
    ", $legacy_raffle_id));

    $pool = [];
    foreach ($rows as $row) {
        $uid = (int) $row->user_id;
        if (in_array($uid, $excluded_users)) continue;
        $pool[] = ['uid' => $uid, 'ticket' => (int) $row->ticket_number];
    }

    return $pool;
}

/** @throws Exception */
function rk_draw_bridge_commit_seed($legacy_raffle_id) {
    global $wpdb;

    $native_raffle_id = rk_draw_bridge_resolve_raffle($legacy_raffle_id);
    if (!$native_raffle_id) {
        throw new Exception('This raffle has not been imported into the native raffle tables yet — run `php artisan legacy:import-raffles` first.');
    }

    $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM raffle_draws WHERE raffle_id = %d', $native_raffle_id), ARRAY_A);
    if ($existing) {
        return $existing; // committing twice just returns the existing commitment — never regenerates one
    }

    $seed = bin2hex(random_bytes(32));
    $now = current_time('mysql');

    $wpdb->insert('raffle_draws', [
        'raffle_id' => $native_raffle_id,
        'server_seed' => $seed,
        'server_seed_hash' => hash('sha256', $seed),
        'committed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM raffle_draws WHERE raffle_id = %d', $native_raffle_id), ARRAY_A);
}

/**
 * @throws Exception if not committed, already run, no prize structure,
 *                    or no eligible entries — same guards
 *                    ProvablyFairDrawService::runDraw() enforces.
 */
function rk_draw_bridge_run_draw($legacy_raffle_id) {
    global $wpdb;

    $native_raffle_id = rk_draw_bridge_resolve_raffle($legacy_raffle_id);
    if (!$native_raffle_id) {
        throw new Exception('This raffle has not been imported into the native raffle tables yet — run `php artisan legacy:import-raffles` first.');
    }

    $draw = $wpdb->get_row($wpdb->prepare('SELECT * FROM raffle_draws WHERE raffle_id = %d', $native_raffle_id), ARRAY_A);
    if (!$draw) {
        throw new Exception('No draw has been committed for this raffle yet.');
    }
    if (!empty($draw['executed_at'])) {
        throw new Exception('This draw has already run.');
    }

    $winners_table = $wpdb->prefix . 'raffle_winners';
    $existing_winners = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $winners_table WHERE raffle_id = %d", $legacy_raffle_id));
    if ($existing_winners > 0) {
        throw new Exception('Draw already completed for this raffle.');
    }

    $prize_tiers = $wpdb->get_results($wpdb->prepare(
        'SELECT tier_name, prize_description, cash_value, winner_count FROM raffle_prize_tiers WHERE raffle_id = %d ORDER BY `rank` ASC',
        $native_raffle_id
    ), ARRAY_A);
    if (empty($prize_tiers)) {
        throw new Exception('No prize structure found. Configure Raffle Details first.');
    }

    $pool = rk_draw_bridge_eligible_pool($legacy_raffle_id);
    if (empty($pool)) {
        throw new Exception('No eligible users found (all in cooldown or no confirmed tickets sold).');
    }

    $client_seed = hash('sha256', implode(',', array_map(fn ($e) => "{$e['uid']}:{$e['ticket']}", $pool)));
    $shuffled = rk_draw_bridge_deterministic_shuffle($pool, $draw['server_seed'], $client_seed);
    $winners = rk_draw_bridge_assign_prizes($legacy_raffle_id, $shuffled, $prize_tiers);

    $wpdb->query('START TRANSACTION');
    try {
        foreach ($winners as $win) {
            $inserted = $wpdb->insert($winners_table, [
                'raffle_id' => $win['raffle_id'],
                'user_id' => $win['user_id'],
                'ticket_number' => $win['ticket_number'],
                'prize_name' => $win['prize_name'],
                'prize_rank' => $win['prize_rank'],
                'prize_cash_value' => $win['prize_cash_value'],
                'won_at' => current_time('mysql'),
                'is_credited' => 0,
                'is_visible' => 0, // requires a separate admin approval, same as the legacy draw
            ]);
            if ($inserted === false) {
                throw new Exception('Failed to save a winner record. Please try again.');
            }
        }

        $wpdb->update('raffle_draws', [
            'client_seed' => $client_seed,
            'executed_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['raffle_id' => $native_raffle_id]);

        $wpdb->query('COMMIT');
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }

    update_post_meta($legacy_raffle_id, 'draw_status', 'active');

    return ['winner_count' => count($winners)];
}
