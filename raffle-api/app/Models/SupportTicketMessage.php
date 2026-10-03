<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * One message in a support ticket. Screenshots the customer attached are
 * kept on the private disk (never a public web address); `attachment_urls`
 * gives short-lived signed links for the customer and staff to view them.
 */
class SupportTicketMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['support_ticket_id', 'author_id', 'is_from_admin', 'message', 'created_at', 'legacy_message_id', 'is_automated', 'attachments'];

    protected $casts = [
        'is_from_admin' => 'boolean',
        'is_automated' => 'boolean',
        'created_at' => 'datetime',
        'attachments' => 'array',
    ];

    protected $hidden = ['attachments'];

    protected $appends = ['attachment_urls'];

    /** @return list<string> */
    public function getAttachmentUrlsAttribute(): array
    {
        return collect($this->attachments ?? [])->keys()
            ->map(fn (int $i) => URL::temporarySignedRoute('support.attachment', now()->addHours(2), ['message' => $this->id, 'index' => $i]))
            ->all();
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author()
    {
        return $this->belongsTo(WpUser::class, 'author_id', 'ID');
    }
}
