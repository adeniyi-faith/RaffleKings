<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One real, already-settled legacy-PHP ticket purchase, queued for
 * comparison against what the new Laravel settlement path would have
 * produced for the same inputs. See OVERHAUL_CHECKLIST.md Phase 3 item
 * 32 and the migration's docblock. Rows are created ONLY by legacy PHP
 * (wp/wp-content/mu-plugins/rk-core/api-financials.php) writing directly
 * to this table after its own commit; `status`/`laravel_*`/`*_mismatch`
 * columns are filled in afterward by
 * App\Services\ShadowPurchaseComparisonService via
 * App\Console\Commands\ProcessShadowPurchaseComparisons.
 */
class ShadowPurchaseComparison extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id',
        'raffle_id',
        'ticket_numbers',
        'quantity',
        'unit_price',
        'is_golden_box',
        'funding_source',
        'legacy_charged_amount',
        'legacy_new_balance',
        'status',
        'laravel_expected_price',
        'price_mismatch',
        'laravel_wallet_balance',
        'entries_missing',
        'mismatch_details',
        'compared_at',
    ];

    protected $casts = [
        'ticket_numbers' => 'array',
        'unit_price' => 'decimal:2',
        'is_golden_box' => 'boolean',
        'legacy_charged_amount' => 'decimal:2',
        'legacy_new_balance' => 'decimal:2',
        'laravel_expected_price' => 'decimal:2',
        'price_mismatch' => 'boolean',
        'laravel_wallet_balance' => 'decimal:2',
        'entries_missing' => 'boolean',
        'mismatch_details' => 'array',
        'compared_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function hasMismatch(): bool
    {
        return (bool) $this->price_mismatch || (bool) $this->entries_missing;
    }
}
