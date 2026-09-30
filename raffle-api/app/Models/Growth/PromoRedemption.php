<?php

namespace App\Models\Growth;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

/** One use of a promo code. */
class PromoRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['promo_code_id', 'user_id', 'context', 'value', 'raffle_transaction_id'];

    protected $casts = ['value' => 'float'];

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }
}
