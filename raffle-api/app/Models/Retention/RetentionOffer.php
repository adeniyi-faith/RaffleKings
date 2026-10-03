<?php

namespace App\Models\Retention;

use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use Illuminate\Database\Eloquent\Model;

/** A personal, time-limited offer to bring a customer back (App\Services\Retention\ComebackOffers). */
class RetentionOffer extends Model
{
    public const KINDS = [
        'credit' => 'Ticket credit',
        'raffle_ticket' => 'A ticket for a raffle (as ticket credit)',
        'points' => 'Bonus points',
    ];

    public const STATUSES = ['open' => 'Waiting to be claimed', 'claimed' => 'Claimed', 'expired' => 'Ran out', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'spend_after' => 'float',
        'channels' => 'array',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'last_call_sent_at' => 'datetime',
        'first_purchase_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    public function raffle()
    {
        return $this->belongsTo(Raffle::class);
    }

    public function isMoney(): bool
    {
        return $this->kind !== 'points';
    }

    public function isClaimable(): bool
    {
        return $this->status === 'open' && $this->expires_at->isFuture();
    }

    /** "₦500 ticket credit" or "300 points". */
    public function prizeText(): string
    {
        return $this->isMoney() ? '₦'.number_format($this->amount).' ticket credit' : number_format($this->amount).' points';
    }

    public function url(): string
    {
        return '/offers/'.$this->token;
    }
}
