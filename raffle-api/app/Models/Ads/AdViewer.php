<?php

namespace App\Models\Ads;

use Illuminate\Database\Eloquent\Model;

/** How many times one person saw and tapped one ad on one day. */
class AdViewer extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['day' => 'date', 'views' => 'integer', 'clicks' => 'integer', 'first_click_at' => 'datetime'];
}
