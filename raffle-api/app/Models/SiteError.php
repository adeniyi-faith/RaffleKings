<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A distinct server error and how often it has happened (App\Services\Monitoring\ErrorAlerter). */
class SiteError extends Model
{
    public $timestamps = false;

    protected $fillable = ['fingerprint', 'title', 'message', 'details', 'occurrences', 'first_seen_at', 'last_seen_at'];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'occurrences' => 'integer',
    ];
}
