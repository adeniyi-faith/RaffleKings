<?php

namespace App\Services\Messaging;

use App\Jobs\SendBroadcast;
use App\Models\Broadcast;
use App\Models\CustomerMessage;
use App\Models\Legacy\WpUser;
use App\Notifications\BroadcastMessage;
use App\Services\AdminAuditLogService;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a message to a group of customers: into their on-site inbox (the
 * bell), by email and/or as a phone notification. The actual sending runs
 * in the background (App\Jobs\SendBroadcast), so a message to thousands
 * of customers never slows the admin down.
 */
final class BroadcastService
{
    public function __construct(private readonly Audience $audience) {}

    /** @param  array{title: string, body: string, link_url?: ?string, link_label?: ?string, channels: list<string>, audience: string, audience_options?: array}  $data */
    public function send(array $data, WpUser $admin): Broadcast
    {
        $broadcast = Broadcast::create([
            ...$data,
            'audience_options' => $data['audience_options'] ?? [],
            'status' => 'sending',
            'recipients_count' => $this->audience->count($data['audience'], $data['audience_options'] ?? []),
            'created_by' => $admin->ID,
        ]);

        app(AdminAuditLogService::class)->record($admin, 'broadcast.sent', Broadcast::class, $broadcast->id, [
            'title' => $broadcast->title,
            'to' => $this->audience->describe($broadcast->audience, $broadcast->audience_options ?? []),
            'recipients' => $broadcast->recipients_count,
            'channels' => $broadcast->channels,
        ]);

        SendBroadcast::dispatch($broadcast->id);

        return $broadcast;
    }

    /** Deliver to one batch of customers. @param  iterable<WpUser>  $users */
    public function deliver(Broadcast $broadcast, iterable $users): void
    {
        $users = collect($users);

        if (in_array('inbox', $broadcast->channels, true)) {
            CustomerMessage::insert($users->map(fn (WpUser $u) => [
                'user_id' => $u->ID,
                'broadcast_id' => $broadcast->id,
                'title' => self::personalise($broadcast->title, $u),
                'body' => self::personalise($broadcast->body, $u),
                'link_url' => $broadcast->link_url,
                'link_label' => $broadcast->link_label,
                'created_at' => now(),
            ])->all());
        }

        $outside = array_values(array_intersect($broadcast->channels, ['email', 'push']));

        if ($outside !== []) {
            Notification::send($users, new BroadcastMessage($broadcast, $outside));
        }
    }

    /** "Send me a test": the same message to the admin only, right now. */
    public function sendTest(array $data, WpUser $admin): void
    {
        $preview = new Broadcast([...$data, 'title' => '[Test] '.$data['title']]);
        $preview->id = 0;

        if (in_array('inbox', $data['channels'], true)) {
            CustomerMessage::create([
                'user_id' => $admin->ID,
                'title' => self::personalise($preview->title, $admin),
                'body' => self::personalise($preview->body, $admin),
                'link_url' => $preview->link_url,
                'link_label' => $preview->link_label,
                'created_at' => now(),
            ]);
        }

        $outside = array_values(array_intersect($data['channels'], ['email', 'push']));
        if ($outside !== []) {
            $admin->notifyNow(new BroadcastMessage($preview, $outside));
        }
    }

    public static function firstName(WpUser $user): string
    {
        return $user->metaValue('first_name') ?: ($user->display_name ?: $user->user_login);
    }

    /** {name} → the customer's name. */
    public static function personalise(string $text, WpUser $user): string
    {
        return str_replace(['{name}', '{username}'], [self::firstName($user), $user->user_login], $text);
    }

    public static function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? url($url) : $url;
    }
}
