<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = ['user_id', 'subject', 'status'];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }
}
