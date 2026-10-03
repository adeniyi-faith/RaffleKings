<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One day of a Daily Drop: the pot, the winners, and the proof the pick was fair. */
class DailyDropRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['daily_drop_id', 'run_date', 'result', 'sales_counted', 'pot', 'tickets_in_pool', 'pool_hash', 'server_seed', 'seed_hash', 'winners', 'created_at'];

    protected $casts = [
        'run_date' => 'date',
        'sales_counted' => 'float',
        'pot' => 'float',
        'tickets_in_pool' => 'integer',
        'winners' => 'array',
        'created_at' => 'datetime',
    ];

    public function drop(): BelongsTo
    {
        return $this->belongsTo(DailyDrop::class, 'daily_drop_id');
    }
}
