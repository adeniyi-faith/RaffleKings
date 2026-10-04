<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One restriction on a customer's account. See App\Services\AccountRestrictions. */
class AccountRestriction extends Model
{
    public const TYPES = [
        'full_ban' => 'Full account ban',
        'no_withdraw' => 'No withdrawals',
        'no_transfer' => 'No transfers',
    ];

    protected $fillable = [
        'user_id', 'type', 'reason', 'source', 'set_by', 'starts_at', 'ends_at',
        'lift_requested_by', 'lift_reason', 'lifted_at', 'lifted_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'lifted_at' => 'datetime',
    ];

    /** In force right now: started, not ended, not lifted. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
