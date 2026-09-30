<?php

namespace App\Models\Growth;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

/** Commission on one top-up by a customer an affiliate brought. */
class AffiliateEarning extends Model
{
    protected $fillable = ['affiliate_id', 'customer_id', 'source_type', 'source_id', 'base_amount', 'commission', 'status', 'note', 'available_at', 'paid_at'];

    protected $casts = [
        'base_amount' => 'float',
        'commission' => 'float',
        'available_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function customer()
    {
        return $this->belongsTo(WpUser::class, 'customer_id', 'ID');
    }
}
