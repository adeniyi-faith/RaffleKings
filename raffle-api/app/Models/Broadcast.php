<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

/** One message sent to a group of customers (App\Services\Messaging\BroadcastService). */
class Broadcast extends Model
{
    public const CHANNELS = ['inbox' => 'On the site (message bell)', 'email' => 'Email', 'push' => 'Phone notification'];

    protected $fillable = ['title', 'body', 'link_url', 'link_label', 'channels', 'audience', 'audience_options', 'status', 'recipients_count', 'created_by', 'sent_at'];

    protected $casts = [
        'channels' => 'array',
        'audience_options' => 'array',
        'recipients_count' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(WpUser::class, 'created_by', 'ID');
    }

    public function inboxMessages()
    {
        return $this->hasMany(CustomerMessage::class);
    }
}
