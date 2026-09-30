<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One reminder sent to one customer (never the same one twice). */
class ReminderSend extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'kind', 'subject', 'channels', 'sent_at'];

    protected $casts = ['channels' => 'array', 'sent_at' => 'datetime'];
}
