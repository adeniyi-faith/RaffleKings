<?php

namespace App\Services\Retention;

use App\Models\Retention\MessageDelivery;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Contracts\TracksDelivery;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes down every message we send, one row per customer per channel,
 * and what happened to it:
 *
 *  - sent / failed: the email or push provider took it, or gave up after
 *    its retries (on-site messages are "sent" the moment they're saved);
 *  - opened: they read it on the site, or their email app loaded the
 *    email's invisible 1-pixel image (many email apps block this, so
 *    email opens are a low estimate);
 *  - clicked: they tapped the message's button or link. Links in emails
 *    and pushes go through /m/{token}, which notes the tap and forwards
 *    them on.
 *
 * Member segments use the taps to learn which channel each customer
 * actually answers (App\Services\Retention\MemberSegments::bestChannel).
 */
class DeliveryTracker
{
    public const LINK_PREFIX = '/m/';

    /**
     * New rows for many customers at once.
     *
     * @param  list<array{user_id: int, channel: string, target_url?: ?string}>  $rows
     * @return array<int, array<string, array{id: int, token: string}>> user id => channel => row
     */
    public function createMany(string $source, int $sourceId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $now = now();
        $insert = array_map(fn (array $r) => [
            'user_id' => $r['user_id'],
            'source' => $source,
            'source_id' => $sourceId,
            'channel' => $r['channel'],
            'token' => Str::random(32),
            'target_url' => isset($r['target_url']) ? mb_substr((string) $r['target_url'], 0, 500) : null,
            // An on-site message is delivered the moment it's saved.
            'status' => $r['channel'] === 'inbox' ? 'sent' : 'queued',
            'sent_at' => $r['channel'] === 'inbox' ? $now : null,
            'created_at' => $now,
        ], $rows);

        foreach (array_chunk($insert, 500) as $chunk) {
            MessageDelivery::query()->insert($chunk);
        }

        $ids = MessageDelivery::query()->whereIn('token', array_column($insert, 'token'))->pluck('id', 'token');
        $out = [];

        foreach ($insert as $r) {
            $out[$r['user_id']][$r['channel']] = ['id' => (int) $ids[$r['token']], 'token' => $r['token']];
        }

        return $out;
    }

    public static function linkUrl(string $token): string
    {
        return url(self::LINK_PREFIX.$token);
    }

    public static function pixelUrl(string $token): string
    {
        return url(self::LINK_PREFIX.$token.'/open.gif');
    }

    /** The invisible 1-pixel image that tells us an email was opened. */
    public static function pixel(string $token): HtmlString
    {
        return new HtmlString('<img src="'.e(self::pixelUrl($token)).'" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px">');
    }

    /** The token for this customer's copy of a message by one channel. */
    public static function tokenFor(string $source, int $sourceId, int $userId, string $channel): ?string
    {
        return MessageDelivery::query()->where('source', $source)->where('source_id', $sourceId)
            ->where('user_id', $userId)->where('channel', $channel)->value('token');
    }

    /** A tap on a tracked link. Returns where to send them, or null for an unknown link. */
    public function click(string $token): ?string
    {
        $row = MessageDelivery::query()->where('token', $token)->first();

        if (! $row) {
            return null;
        }

        $row->clicked_at ??= now();
        $row->opened_at ??= now();
        $row->save();

        return $row->target_url ?: '/';
    }

    public function opened(string $token): void
    {
        MessageDelivery::query()->where('token', $token)->whereNull('opened_at')->update(['opened_at' => now()]);
    }

    /** They read an on-site message (or tapped its button). */
    public function openedInbox(int $deliveryId, bool $clicked = false): void
    {
        MessageDelivery::query()->whereKey($deliveryId)->whereNull('opened_at')->update(['opened_at' => now()]);

        if ($clicked) {
            MessageDelivery::query()->whereKey($deliveryId)->whereNull('clicked_at')->update(['clicked_at' => now()]);
        }
    }

    /** A notification left us by one channel (Laravel's NotificationSent event). */
    public function notificationSent(mixed $notifiable, mixed $notification, string $channel): void
    {
        $key = self::channelKey($channel);

        if (! $notification instanceof TracksDelivery || ! $key) {
            return;
        }

        if ($token = $notification->deliveryToken($key, $notifiable)) {
            MessageDelivery::query()->where('token', $token)->where('status', 'queued')->update(['status' => 'sent', 'sent_at' => now()]);
        }
    }

    /** A queued notification gave up after its retries (Laravel's JobFailed event). */
    public function jobFailed(mixed $job, Throwable $e): void
    {
        try {
            $payload = $job->payload();

            if (($payload['data']['commandName'] ?? null) !== SendQueuedNotifications::class) {
                return;
            }

            $command = unserialize($payload['data']['command'] ?? '');

            if (! $command instanceof SendQueuedNotifications || ! $command->notification instanceof TracksDelivery) {
                return;
            }

            foreach ($command->notifiables as $notifiable) {
                foreach ((array) $command->channels as $channel) {
                    $key = self::channelKey($channel);
                    $token = $key ? $command->notification->deliveryToken($key, $notifiable) : null;

                    if ($token) {
                        MessageDelivery::query()->where('token', $token)->where('status', 'queued')
                            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
                    }
                }
            }
        } catch (Throwable $inner) {
            report($inner);
        }
    }

    public static function channelKey(string $channel): ?string
    {
        return match ($channel) {
            'mail' => 'email',
            OneSignalChannel::class => 'push',
            default => null,
        };
    }

    /**
     * Totals by channel for one message (or one kind of message).
     *
     * @return array<string, array{total: int, sent: int, failed: int, queued: int, opened: int, clicked: int}>
     */
    public function stats(string|array $source, ?int $sourceId = null, ?\DateTimeInterface $since = null): array
    {
        $rows = MessageDelivery::query()
            ->whereIn('source', (array) $source)
            ->when($sourceId !== null, fn ($q) => $q->where('source_id', $sourceId))
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('channel')
            ->select('channel')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued")
            ->selectRaw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened')
            ->selectRaw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked')
            ->get()
            ->keyBy('channel');

        $out = [];
        foreach (array_keys(MessageDelivery::CHANNELS) as $channel) {
            $r = $rows[$channel] ?? null;
            $out[$channel] = [
                'total' => (int) ($r->total ?? 0),
                'sent' => (int) ($r->sent ?? 0),
                'failed' => (int) ($r->failed ?? 0),
                'queued' => (int) ($r->queued ?? 0),
                'opened' => (int) ($r->opened ?? 0),
                'clicked' => (int) ($r->clicked ?? 0),
            ];
        }

        return $out;
    }

    /** Old tracking rows are only needed for a while: keep 180 days. */
    public function prune(): int
    {
        $ids = DB::table('message_deliveries')->where('created_at', '<', now()->subDays(180))->limit(5000)->pluck('id');

        return $ids->isEmpty() ? 0 : DB::table('message_deliveries')->whereIn('id', $ids)->delete();
    }
}
