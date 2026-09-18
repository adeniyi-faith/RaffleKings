<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class ReferralClick extends Model
{
    protected $fillable = [
        'referrer_user_id',
        'visitor_token',
    ];

    public function referrer()
    {
        return $this->belongsTo(WpUser::class, 'referrer_user_id', 'ID');
    }
}
