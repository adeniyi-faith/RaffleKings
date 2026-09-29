<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['support_ticket_id', 'author_id', 'is_from_admin', 'message', 'created_at', 'legacy_message_id', 'is_automated'];

    protected $casts = [
        'is_from_admin' => 'boolean',
        'is_automated' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author()
    {
        return $this->belongsTo(WpUser::class, 'author_id', 'ID');
    }
}
