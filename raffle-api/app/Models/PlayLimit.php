<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer's own spending limits and break (App\Services\ResponsiblePlayService). */
class PlayLimit extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'daily_limit', 'weekly_limit', 'monthly_limit', 'pending_limits', 'pending_from', 'excluded_until'];

    protected $casts = [
        'daily_limit' => 'decimal:2',
        'weekly_limit' => 'decimal:2',
        'monthly_limit' => 'decimal:2',
        'pending_limits' => 'array',
        'pending_from' => 'datetime',
        'excluded_until' => 'datetime',
    ];
}
