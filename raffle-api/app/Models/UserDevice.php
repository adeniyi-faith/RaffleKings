<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A browser a customer used, by its random rk_did cookie (multi-account protection). */
class UserDevice extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'device_id', 'ip', 'first_seen_at', 'last_seen_at'];

    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
}
