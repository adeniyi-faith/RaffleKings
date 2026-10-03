<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a customer's Lucky Meter history: money added from a raffle, or a fill paid out. */
class LuckyMeterEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'raffle_id', 'kind', 'amount'];

    protected $casts = ['user_id' => 'integer', 'raffle_id' => 'integer', 'amount' => 'float'];

    public function raffle()
    {
        return $this->belongsTo(Raffle::class);
    }
}
