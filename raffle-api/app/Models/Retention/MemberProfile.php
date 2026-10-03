<?php

namespace App\Models\Retention;

use App\Services\Retention\MemberSegments;
use Illuminate\Database\Eloquent\Model;

/** One customer's numbers and segment, worked out every night (App\Services\Retention\MemberSegments). */
class MemberProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'segment_since' => 'datetime',
        'joined_at' => 'datetime',
        'first_play_at' => 'datetime',
        'last_play_at' => 'datetime',
        'last_visit_at' => 'datetime',
        'last_win_at' => 'datetime',
        'refreshed_at' => 'datetime',
        'play_days' => 'integer',
        'play_days_30' => 'integer',
        'active_weeks_8' => 'integer',
        'visit_days_30' => 'integer',
        'wins' => 'integer',
        'spend_total' => 'float',
        'spend_30' => 'float',
        'spend_prev_30' => 'float',
        'avg_order' => 'float',
        'wallet_balance' => 'float',
        'earnings_balance' => 'float',
    ];

    /** @return list<string> */
    public function flags(): array
    {
        return MemberSegments::flagsFor($this->user_id);
    }
}
