<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Daily Drop set up on one raffle. See App\Services\Engagement\DailyDrops. */
class DailyDrop extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'active' => 'Running', 'paused' => 'Paused', 'ended' => 'Ended'];

    protected $fillable = ['raffle_id', 'status', 'pot_percent', 'daily_cap', 'winners_per_day', 'drop_time', 'next_seed', 'next_seed_hash', 'counting_from', 'created_by', 'advisor_report_id'];

    protected $hidden = ['next_seed'];

    protected $casts = [
        'pot_percent' => 'float',
        'daily_cap' => 'float',
        'winners_per_day' => 'integer',
        'counting_from' => 'datetime',
    ];

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DailyDropRun::class)->latest('run_date');
    }
}
