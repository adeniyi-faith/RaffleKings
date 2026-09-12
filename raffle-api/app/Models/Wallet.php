<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = ['user_id', 'wallet_balance', 'earnings_balance'];

    protected $casts = [
        'wallet_balance' => 'decimal:2',
        'earnings_balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
