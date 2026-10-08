<?php

namespace App\Models\Ads;

use Illuminate\Database\Eloquent\Model;

/** One day's views, taps and closes for one version of an ad in one spot. */
class AdStat extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['day' => 'date', 'views' => 'integer', 'clicks' => 'integer', 'closes' => 'integer'];
}
