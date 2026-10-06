<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A payment webhook we received, stored once by the provider's own event key. */
class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'gateway', 'event_key', 'event_type', 'reference', 'payload_hash',
        'status', 'error', 'deliveries', 'received_at', 'processed_at',
    ];

    protected $casts = ['received_at' => 'datetime', 'processed_at' => 'datetime'];
}
