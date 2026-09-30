<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

/** One message sent to a group of customers (App\Services\Messaging\BroadcastService). */
class Broadcast extends Model
{
    public const CHANNELS = ['inbox' => 'On the site (message bell)', 'email' => 'Email', 'push' => 'Phone notification'];

    protected $fillable = [
        'title', 'body', 'link_url', 'link_label', 'channels', 'audience', 'audience_options', 'status', 'recipients_count', 'created_by', 'sent_at',
        'scheduled_at', 'last_user_id', 'delivered_count', 'skipped_count', 'is_promotion', 'started_at', 'error',
    ];

    protected $casts = [
        'channels' => 'array',
        'audience_options' => 'array',
        'recipients_count' => 'integer',
        'delivered_count' => 'integer',
        'skipped_count' => 'integer',
        'is_promotion' => 'boolean',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
    ];

    /** scheduled → sending → sent. Stopped early: cancelled. Hit a problem: failed (can be resumed). */
    public const STATUSES = ['scheduled', 'sending', 'sent', 'failed', 'cancelled'];

    public function sender()
    {
        return $this->belongsTo(WpUser::class, 'created_by', 'ID');
    }

    public function inboxMessages()
    {
        return $this->hasMany(CustomerMessage::class);
    }
}
