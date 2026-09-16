<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class PointLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'direction',
        'amount',
        'reason',
        'description',
        'reference_type',
        'reference_id',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
