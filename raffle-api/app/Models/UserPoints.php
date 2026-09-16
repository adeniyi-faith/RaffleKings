<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class UserPoints extends Model
{
    protected $table = 'user_points';

    protected $fillable = ['user_id', 'balance', 'streak_count', 'last_claim_date'];

    protected $casts = [
        'balance' => 'integer',
        'streak_count' => 'integer',
        'last_claim_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
