<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers the pure, database-free parts of rewards-bridge.php — the port
 * of DailyClaimService's streak logic and the constant tables that must
 * stay in sync with SpinService/TaskClaimService/DailyClaimService on
 * the Laravel side (see rewards-bridge.php's own docblock).
 */
final class RewardsEngineTest extends TestCase
{
    public function test_a_first_ever_claim_starts_the_streak_at_one(): void
    {
        $this->assertSame(1, rk_points_next_streak(null, 0, '2026-01-10'));
    }

    public function test_a_consecutive_day_claim_increments_the_streak(): void
    {
        $this->assertSame(4, rk_points_next_streak('2026-01-09', 3, '2026-01-10'));
    }

    public function test_the_streak_cycles_back_to_one_after_day_seven(): void
    {
        $this->assertSame(1, rk_points_next_streak('2026-01-09', 7, '2026-01-10'));
    }

    public function test_missing_a_day_resets_the_streak_to_one(): void
    {
        $this->assertSame(1, rk_points_next_streak('2026-01-05', 5, '2026-01-10'));
    }

    public function test_daily_claim_rewards_schedule_has_seven_escalating_entries(): void
    {
        $this->assertSame([50, 70, 100, 150, 200, 300, 1000], RK_DAILY_CLAIM_REWARDS);
    }

    public function test_spin_prize_weights_sum_to_one_thousand(): void
    {
        $total = array_sum(array_column(RK_SPIN_PRIZES, 'weight'));
        $this->assertSame(1000, $total);
    }

    public function test_task_rewards_match_the_laravel_port(): void
    {
        $this->assertSame([
            'push_notification' => 1500,
            'join_community' => 1300,
            'whatsapp_follow' => 800,
            'whatsapp_share' => 500,
        ], RK_TASK_REWARDS);
    }

    public function test_whatsapp_share_is_the_one_repeatable_daily_task(): void
    {
        $this->assertSame(['whatsapp_share'], RK_TASK_REPEATABLE_DAILY);
    }
}
