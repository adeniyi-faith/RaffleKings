<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A gaming tax reminder that has been sent — see App\Services\GamingTaxReminders. */
class GamingTaxReminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['period', 'kind', 'sent_to', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime', 'sent_to' => 'integer'];
}
