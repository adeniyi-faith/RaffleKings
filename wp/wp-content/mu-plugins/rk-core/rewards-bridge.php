<?php
/**
 * Module: Rewards Bridge (OVERHAUL_CHECKLIST.md Phase 3 item 35c)
 *
 * Points, the daily login streak, tasks, and Spin & Win all currently
 * live as loose wp_usermeta rows (rk_points, rk_streak_count,
 * rk_last_claim_date, rk_completed_tasks, rk_last_share_date) with no
 * locking — two rapid daily-claim requests, or two rapid spins, can both
 * read "not claimed yet" / "enough points" and both succeed (the exact
 * TD-06-class race already fixed for wallets in item 33). Phase 1
 * item 16 (extended by item 28) already built the real fix on the
 * Laravel side — PointsService/DailyClaimService/TaskClaimService/
 * SpinService/PointRedemptionService, backed by native `user_points`/
 * `completed_tasks`/`point_ledger_entries` tables with real row
 * locking — but nothing in WordPress ever called it, so every real
 * claim/spin/task/redemption still runs the old, unlocked usermeta path.
 *
 * This file is a faithful PHP port of those five services (keep this
 * file and them in sync if either ever changes) so legacy PHP can run
 * the SAME locked, ledgered rewards logic directly against the shared
 * database — no HTTP call into Laravel, same architecture as every
 * other bridge in this migration.
 *
 * Gated behind rk_rewards_unified_enabled() (a WP option, default off)
 * for the same instant, zero-deploy rollback item 33 established.
 *
 * IMPORTANT — before turning this on, every real user's points balance,
 * streak, and completed tasks need to exist on the native tables too, or
 * they'd appear to reset to zero the instant this becomes the live path.
 * See raffle-api/app/Console/Commands/ReconcilePoints.php
 * (`php artisan legacy:reconcile-points`) — run that FIRST.
 *
 * Redeeming points credits the UNIFIED wallet (wallet-bridge.php's
 * rk_wallet_apply(), item 33) — gated the same way winner-crediting and
 * referral-crediting are: on rk_wallets_unified_enabled(), independently
 * of this file's own flag, since it's the wallet flag that decides where
 * real money lands, regardless of which system decided to move it there.
 */

if (!defined('ABSPATH')) {
    exit;
}

function rk_rewards_unified_enabled() {
    return get_option('rk_rewards_unified_enabled', '0') === '1';
}

/** Same 7-day schedule as DailyClaimService::REWARDS / the legacy $rewards array. */
const RK_DAILY_CLAIM_REWARDS = [50, 70, 100, 150, 200, 300, 1000];

/** Same task list/rewards as TaskClaimService::REWARDS / the legacy $task_rewards array. */
const RK_TASK_REWARDS = [
    'push_notification' => 1500,
    'join_community' => 1300,
    'whatsapp_follow' => 800,
    'whatsapp_share' => 500,
];

const RK_TASK_REPEATABLE_DAILY = ['whatsapp_share'];

/** [payout, weight out of 1000, outcome] — mirrors SpinService::PRIZES. */
const RK_SPIN_PRIZES = [
    ['payout' => 15, 'weight' => 600, 'outcome' => 'loss'],
    ['payout' => 50, 'weight' => 300, 'outcome' => 'tie'],
    ['payout' => 150, 'weight' => 80, 'outcome' => 'win'],
    ['payout' => 500, 'weight' => 20, 'outcome' => 'jackpot'],
];

const RK_SPIN_COST = 50;

const RK_REDEMPTION_CONVERSION_RATE = 10; // points per naira

const RK_REDEMPTION_MINIMUM_POINTS = 100;

/** Ensures a `user_points` row exists and locks it. MUST be called inside an open transaction. */
function rk_points_lock_row($user_id) {
    global $wpdb;

    $wpdb->query($wpdb->prepare(
        "INSERT INTO user_points (user_id, balance, streak_count, created_at, updated_at)
         VALUES (%d, 0, 0, %s, %s)
         ON DUPLICATE KEY UPDATE user_id = user_id",
        $user_id, current_time('mysql'), current_time('mysql')
    ));

    return $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM user_points WHERE user_id = %d FOR UPDATE',
        $user_id
    ), ARRAY_A);
}

function rk_points_read_balance($user_id) {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare('SELECT balance FROM user_points WHERE user_id = %d', $user_id));

    return $value !== null ? (int) $value : 0;
}

function rk_points_record_ledger($user_id, $direction, $amount, $reason, $reference_type = null, $reference_id = null, $description = null) {
    global $wpdb;

    $wpdb->insert('point_ledger_entries', [
        'user_id' => $user_id,
        'direction' => $direction,
        'amount' => $amount,
        'reason' => $reason,
        'description' => $description,
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'created_at' => current_time('mysql'),
    ]);
}

/**
 * Locked add/subtract of a signed delta against the points balance, with
 * a permanent ledger entry — opens and closes its OWN transaction, so
 * this is safe to call as a single, complete operation (mirrors
 * wallet-bridge.php's rk_wallet_apply()).
 *
 * @throws Exception if the resulting balance would go negative.
 */
function rk_points_apply($user_id, $delta, $reason, $reference_type = null, $reference_id = null, $description = null) {
    global $wpdb;

    $wpdb->query('START TRANSACTION');
    try {
        $row = rk_points_lock_row($user_id);
        $new_balance = (int) $row['balance'] + $delta;

        if ($new_balance < 0) {
            throw new Exception('Insufficient points');
        }

        $wpdb->update('user_points', ['balance' => $new_balance, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);
        rk_points_record_ledger($user_id, $delta >= 0 ? 'credit' : 'debit', abs($delta), $reason, $reference_type, $reference_id, $description);

        $wpdb->query('COMMIT');

        return $new_balance;
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

/** Mirrors DailyClaimService::nextStreak(). $last_claim_date/$now are 'Y-m-d' strings. */
function rk_points_next_streak($last_claim_date, $current_streak, $now) {
    if (!$last_claim_date) {
        return 1;
    }

    $yesterday = date('Y-m-d', strtotime($now . ' -1 day'));
    if ($last_claim_date === $yesterday) {
        $next = ($current_streak ?: 1) + 1;

        return $next > 7 ? 1 : $next;
    }

    return 1; // missed at least a day — streak resets
}

/**
 * Mirrors DailyClaimService::state() — what the Rewards hub renders.
 *
 * @return array{streak:int, is_claimed_today:bool}
 */
function rk_points_bridge_daily_state($user_id) {
    global $wpdb;
    $today = date('Y-m-d', current_time('timestamp'));
    $row = $wpdb->get_row($wpdb->prepare('SELECT streak_count, last_claim_date FROM user_points WHERE user_id = %d', $user_id), ARRAY_A);

    $streak_count = $row ? (int) $row['streak_count'] : 0;
    $last_claim_date = $row ? $row['last_claim_date'] : null;
    $claimed_today = $last_claim_date === $today;

    $streak = $claimed_today ? $streak_count : rk_points_next_streak($last_claim_date, $streak_count, $today);

    return ['streak' => $streak, 'is_claimed_today' => $claimed_today];
}

/**
 * Mirrors DailyClaimService::claim().
 *
 * @throws Exception if already claimed today.
 * @return array{points_added:int, new_streak:int, new_total_points:int}
 */
function rk_points_bridge_daily_claim($user_id) {
    global $wpdb;
    $today = date('Y-m-d', current_time('timestamp'));

    $wpdb->query('START TRANSACTION');
    try {
        $row = rk_points_lock_row($user_id);

        if ($row['last_claim_date'] === $today) {
            throw new Exception('Already claimed today');
        }

        $streak = rk_points_next_streak($row['last_claim_date'], (int) $row['streak_count'], $today);
        $reward = RK_DAILY_CLAIM_REWARDS[$streak - 1];
        $new_balance = (int) $row['balance'] + $reward;

        $wpdb->update('user_points', [
            'streak_count' => $streak,
            'last_claim_date' => $today,
            'balance' => $new_balance,
            'updated_at' => current_time('mysql'),
        ], ['user_id' => $user_id]);

        rk_points_record_ledger($user_id, 'credit', $reward, 'daily_claim', null, null, "Day {$streak} login streak reward");

        $wpdb->query('COMMIT');

        return ['points_added' => $reward, 'new_streak' => $streak, 'new_total_points' => $new_balance];
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

/**
 * Mirrors TaskClaimService::catalog().
 *
 * @return list<array{task_id:string, points:int, completed:bool, repeatable:bool}>
 */
function rk_points_bridge_task_catalog($user_id) {
    global $wpdb;
    $today = date('Y-m-d', current_time('timestamp'));

    $done_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT task_id FROM completed_tasks WHERE user_id = %d AND task_id NOT IN ('" . implode("','", array_map('esc_sql', RK_TASK_REPEATABLE_DAILY)) . "')",
        $user_id
    ));
    $done_today = $wpdb->get_col($wpdb->prepare(
        "SELECT task_id FROM completed_tasks WHERE user_id = %d AND task_id IN ('" . implode("','", array_map('esc_sql', RK_TASK_REPEATABLE_DAILY)) . "') AND DATE(completed_at) = %s",
        $user_id, $today
    ));

    $catalog = [];
    foreach (RK_TASK_REWARDS as $task_id => $points) {
        $repeatable = in_array($task_id, RK_TASK_REPEATABLE_DAILY, true);
        $catalog[] = [
            'task_id' => $task_id,
            'points' => $points,
            'completed' => $repeatable ? in_array($task_id, $done_today, true) : in_array($task_id, $done_ids, true),
            'repeatable' => $repeatable,
        ];
    }

    return $catalog;
}

/**
 * Mirrors TaskClaimService::claim().
 *
 * @throws Exception if the task id is unknown or already completed.
 * @return array{task_id:string, points_added:int, new_total_points:int}
 */
function rk_points_bridge_task_claim($user_id, $task_id) {
    global $wpdb;

    if (!array_key_exists($task_id, RK_TASK_REWARDS)) {
        throw new Exception('Unknown task: ' . $task_id);
    }

    $repeatable = in_array($task_id, RK_TASK_REPEATABLE_DAILY, true);
    $today = date('Y-m-d', current_time('timestamp'));

    $wpdb->query('START TRANSACTION');
    try {
        if ($repeatable) {
            $already = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM completed_tasks WHERE user_id = %d AND task_id = %s AND DATE(completed_at) = %s',
                $user_id, $task_id, $today
            ));
        } else {
            $already = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM completed_tasks WHERE user_id = %d AND task_id = %s',
                $user_id, $task_id
            ));
        }

        if ($already) {
            throw new Exception('Task already completed: ' . $task_id);
        }

        $wpdb->insert('completed_tasks', [
            'user_id' => $user_id,
            'task_id' => $task_id,
            'completed_at' => current_time('mysql'),
        ]);

        $reward = RK_TASK_REWARDS[$task_id];
        $row = rk_points_lock_row($user_id);
        $new_balance = (int) $row['balance'] + $reward;
        $wpdb->update('user_points', ['balance' => $new_balance, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);
        rk_points_record_ledger($user_id, 'credit', $reward, 'task_claim', null, null, "Completed task: {$task_id}");

        $wpdb->query('COMMIT');

        return ['task_id' => $task_id, 'points_added' => $reward, 'new_total_points' => $new_balance];
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

/**
 * Mirrors SpinService::spin() — random_int() (CSPRNG-backed), not rand().
 *
 * @throws Exception if the user doesn't have enough points.
 * @return array{payout:int, outcome:string, visual_index:int, new_balance:int}
 */
function rk_points_bridge_spin($user_id) {
    global $wpdb;

    $wpdb->query('START TRANSACTION');
    try {
        $row = rk_points_lock_row($user_id);
        $balance_after_cost = (int) $row['balance'] - RK_SPIN_COST;

        if ($balance_after_cost < 0) {
            throw new Exception('Insufficient points');
        }

        $wpdb->update('user_points', ['balance' => $balance_after_cost, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);
        rk_points_record_ledger($user_id, 'debit', RK_SPIN_COST, 'spin_cost', null, null, 'Spin & Win entry fee');

        $roll = random_int(1, 1000);
        $cumulative = 0;
        $prize = RK_SPIN_PRIZES[count(RK_SPIN_PRIZES) - 1];
        $index = count(RK_SPIN_PRIZES) - 1;
        foreach (RK_SPIN_PRIZES as $i => $p) {
            $cumulative += $p['weight'];
            if ($roll <= $cumulative) {
                $prize = $p;
                $index = $i;
                break;
            }
        }

        $new_balance = $balance_after_cost;
        if ($prize['payout'] > 0) {
            $new_balance = $balance_after_cost + $prize['payout'];
            $wpdb->update('user_points', ['balance' => $new_balance, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);
            rk_points_record_ledger($user_id, 'credit', $prize['payout'], 'spin_win', null, null, 'Spin & Win prize: ' . $prize['outcome']);
        }

        $wpdb->query('COMMIT');

        return ['payout' => $prize['payout'], 'outcome' => $prize['outcome'], 'visual_index' => $index, 'new_balance' => $new_balance];
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

/**
 * Mirrors PointRedemptionService::redeem() — credits the SAME unified
 * `wallets` table item 33's rk_wallet_apply() uses, gated on
 * rk_wallets_unified_enabled() independently of this file's own flag
 * (see this file's docblock).
 *
 * @throws Exception if below the minimum, or if the unified wallet
 *                    isn't enabled (redeeming into wp_usermeta directly
 *                    would defeat the point of "one real wallet").
 * @return array{redeemed_points:int, wallet_added:float, new_wallet_balance:float}
 */
function rk_points_bridge_redeem($user_id) {
    if (!rk_wallets_unified_enabled()) {
        throw new Exception('Point redemption requires the unified wallet to be enabled.');
    }

    $current_points = rk_points_read_balance($user_id);
    if ($current_points < RK_REDEMPTION_MINIMUM_POINTS) {
        throw new Exception('Minimum redemption is ' . RK_REDEMPTION_MINIMUM_POINTS . ' Points');
    }

    $wallet_value = intdiv($current_points, RK_REDEMPTION_CONVERSION_RATE);

    // Debit points first (its own locked transaction), then credit the
    // wallet (also its own) — same two-step shape PointRedemptionService
    // uses under one Laravel DB transaction; here each step is already
    // individually safe/atomic via rk_points_apply()/rk_wallet_apply(),
    // and redemption is a rare, user-initiated action rather than a
    // high-frequency path where a gap between the two matters as much
    // as it would for, say, ticket purchase.
    rk_points_apply($user_id, -$current_points, 'redemption', null, null, "Redeemed {$current_points} points for ₦{$wallet_value}");
    $new_wallet_balance = rk_wallet_apply($user_id, 'wallet', $wallet_value, 'points_redemption');

    return ['redeemed_points' => $current_points, 'wallet_added' => (float) $wallet_value, 'new_wallet_balance' => $new_wallet_balance];
}
