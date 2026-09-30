<?php

namespace App\Models\Growth;

use Illuminate\Database\Eloquent\Model;

/** The first promo code or affiliate that brought a customer (set once, at sign-up). */
class CustomerSource extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'promo_code_id', 'affiliate_id'];
}
