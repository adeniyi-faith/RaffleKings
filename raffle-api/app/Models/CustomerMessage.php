<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A message in one customer's in-app inbox (the bell on the site): staff
 * news from Site → Message customers, or a personal alert written by
 * App\Notifications\Channels\InboxChannel.
 */
class CustomerMessage extends Model
{
    public const UPDATED_AT = null;

    /** news = from staff; the rest are personal alerts. */
    public const KINDS = ['news', 'support', 'win', 'wallet', 'withdrawal', 'referral'];

    protected $fillable = ['user_id', 'broadcast_id', 'kind', 'title', 'body', 'link_url', 'link_label', 'read_at', 'created_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind ?? 'news',
            'title' => $this->title,
            'body' => $this->body,
            'link_url' => $this->link_url,
            'link_label' => $this->link_label,
            'is_read' => $this->read_at !== null,
            'sent_at' => $this->created_at?->toIso8601String(),
            'sent_ago' => $this->created_at?->diffForHumans(),
        ];
    }
}
