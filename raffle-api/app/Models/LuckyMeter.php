<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One customer's Lucky Meter (App\Services\Engagement\LuckyMeter). */
class LuckyMeter extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'progress', 'fills', 'total_paid'];

    protected $casts = ['user_id' => 'integer', 'progress' => 'float', 'fills' => 'integer', 'total_paid' => 'float'];
}
