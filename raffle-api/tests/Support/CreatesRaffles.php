<?php

namespace Tests\Support;

use App\Models\Raffle;

/**
 * Creates a native raffle (the public site's only source since
 * OVERHAUL_CHECKLIST.md item 43) from the same field names the old
 * WordPress raffle postmeta used, so tests written against that shape
 * keep reading naturally: price, max, grand_prize, prize_list,
 * prize_type, expiry, is_sold_out ('1' = closed by an admin).
 */
trait CreatesRaffles
{
    /**
     * @param  array<string, mixed>  $meta  legacy-style fields (see above), plus optional title/excerpt/public_id
     * @param  string  $status  'publish' (on sale) or 'draft', as WordPress named them
     */
    protected function createRaffle(array $meta = [], string $status = 'publish'): Raffle
    {
        return Raffle::create(array_filter([
            'public_id' => $meta['public_id'] ?? null,
            'title' => $meta['title'] ?? 'Test Raffle',
            'excerpt' => $meta['excerpt'] ?? null,
            'price' => (float) ($meta['price'] ?? 100),
            'max_tickets' => (int) ($meta['max'] ?? 100),
            'grand_prize' => $meta['grand_prize'] ?? 'A Prize',
            'prize_list' => $meta['prize_list'] ?? null,
            'prize_type' => $meta['prize_type'] ?? 'other',
            'expiry' => ($meta['expiry'] ?? null) ?: null,
            'status' => match (true) {
                $status !== 'publish' => 'draft',
                ($meta['is_sold_out'] ?? '0') === '1' => 'closed',
                default => 'published',
            },
        ], fn ($value) => $value !== null));
    }
}
