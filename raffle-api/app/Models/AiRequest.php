<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One call to the AI (no message text is kept, only what it was for). */
class AiRequest extends Model
{
    public $timestamps = false;

    protected $fillable = ['purpose', 'model', 'support_ticket_id', 'succeeded', 'error', 'created_at'];

    protected $casts = ['succeeded' => 'boolean', 'created_at' => 'datetime'];
}
