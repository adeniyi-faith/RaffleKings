<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers the pure helpers behind the 10-minute number hold. The database
 * parts (claiming, expiry, hand-over to a signed-in account) need a real
 * MySQL and are exercised by hand on staging.
 */
final class NumberHoldsTest extends TestCase
{
    public function test_parse_numbers_keeps_valid_whole_numbers_sorted_and_unique(): void
    {
        $this->assertSame([3, 12, 45], rk_holds_parse_numbers('45, 12,3,12', 100));
    }

    public function test_parse_numbers_drops_junk_and_out_of_range_values(): void
    {
        $this->assertSame([7], rk_holds_parse_numbers('abc,0,-4,101,7,1.5,,7 OR 1=1', 100));
    }

    public function test_parse_numbers_accepts_an_array_and_rejects_other_types(): void
    {
        $this->assertSame([1, 2], rk_holds_parse_numbers([2, '1'], 10));
        $this->assertSame([], rk_holds_parse_numbers(null, 10));
    }

    public function test_parse_numbers_caps_the_size_of_one_request(): void
    {
        $many = implode(',', range(1, 500));
        $this->assertCount(RK_HOLD_MAX_PER_REQUEST, rk_holds_parse_numbers($many, 1000));
    }

    public function test_holder_key_must_be_a_safe_random_looking_token(): void
    {
        $this->assertSame('abcDEF0123456789abcdef', rk_holds_clean_key('abcDEF0123456789abcdef'));
        $this->assertSame('', rk_holds_clean_key('short'));
        $this->assertSame('', rk_holds_clean_key("bad key'; DROP TABLE x;--"));
        $this->assertSame('', rk_holds_clean_key(null));
    }
}
