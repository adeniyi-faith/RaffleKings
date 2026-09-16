<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class ReferralCommission extends Model
{
    protected $fillable = [
        'referrer_user_id',
        'referee_user_id',
        'deposit_amount',
        'commission_amount',
        'commission_rate',
        'deposit_transaction_id',
    ];

    protected $casts = [
        'deposit_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'commission_rate' => 'decimal:4',
    ];

    public function referrer()
    {
        return $this->belongsTo(WpUser::class, 'referrer_user_id', 'ID');
    }

    public function referee()
    {
        return $this->belongsTo(WpUser::class, 'referee_user_id', 'ID');
    }
}
