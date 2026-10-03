<?php

namespace App\Models\Retention;

use Illuminate\Database\Eloquent\Model;

/**
 * One message to one customer by one channel (on the site, email or push):
 * when it was sent or failed, and when they opened it or tapped its link.
 * See App\Services\Retention\DeliveryTracker.
 */
class MessageDelivery extends Model
{
    public const UPDATED_AT = null;

    public const CHANNELS = ['inbox' => 'On the site', 'email' => 'Email', 'push' => 'Push'];

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];
}
