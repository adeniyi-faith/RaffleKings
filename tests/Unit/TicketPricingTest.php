<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers rk_calculate_ticket_price(), the function that decides how much a
 * user is actually charged. checkout.php's price-mismatch guard
 * (SECURITY FIX #2 in api-financials.php) depends on this producing exactly
 * what the frontend expects — a silent regression here means every ticket
 * purchase starts failing (or, worse, undercharging).
 */
final class TicketPricingTest extends TestCase
{
    public function test_single_ticket_has_no_discount(): void
    {
        $this->assertSame(100.0, rk_calculate_ticket_price(1, 100));
        $this->assertSame(300.0, rk_calculate_ticket_price(1, 300));
    }

    public function test_low_price_tier_applies_flat_ten_percent_off_for_two_or_more(): void
    {
        $this->assertSame(180.0, rk_calculate_ticket_price(2, 100));
        $this->assertSame(450.0, rk_calculate_ticket_price(5, 100));
    }

    public function test_high_price_tier_uses_tiered_discounts(): void
    {
        $this->assertEqualsWithDelta(450.0, rk_calculate_ticket_price(2, 300), 0.0001);  // 25% off
        $this->assertEqualsWithDelta(585.0, rk_calculate_ticket_price(3, 300), 0.0001);  // 35% off
        $this->assertEqualsWithDelta(900.0, rk_calculate_ticket_price(5, 300), 0.0001);  // 40% off
        $this->assertEqualsWithDelta(1650.0, rk_calculate_ticket_price(10, 300), 0.0001); // 45% off
    }

    public function test_high_price_tier_over_ten_tickets_gets_fifty_percent_off(): void
    {
        $this->assertSame(2250.0, rk_calculate_ticket_price(15, 300));
    }

    public function test_high_price_tier_quantity_not_in_the_discount_table_gets_no_discount(): void
    {
        // 4 tickets isn't one of the explicit tiers (2, 3, 5, 10) and isn't > 10 either.
        $this->assertSame(1200.0, rk_calculate_ticket_price(4, 300));
    }

    public function test_golden_box_stacks_an_extra_ten_percent_off(): void
    {
        $this->assertEqualsWithDelta(405.0, rk_calculate_ticket_price(2, 300, true), 0.0001);
        $this->assertEqualsWithDelta(162.0, rk_calculate_ticket_price(2, 100, true), 0.0001);
    }
}
