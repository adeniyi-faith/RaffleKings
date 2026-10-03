<?php

namespace App\Services\Messaging;

use App\Jobs\SendBroadcast;
use App\Models\Broadcast;
use App\Models\CustomerMessage;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Notifications\BroadcastMessage;
use App\Services\AdminAuditLogService;
use App\Services\Retention\DeliveryTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a message to a group of customers: into their on-site inbox (the
 * bell), by email and/or as a phone notification. The actual sending runs
 * in the background (App\Jobs\SendBroadcast), so a message to thousands
 * of customers never slows the admin down.
 *
 * A message can go now or at a chosen time. Sending works through the
 * group in order of customer id, a batch at a time, and writes down who
 * has been reached (broadcast_deliveries, one row per customer per
 * message). If sending is interrupted it carries on where it stopped, and
 * nobody can ever get the same message twice.
 */
final class BroadcastService
{
    public function __construct(private readonly Audience $audience) {}

    /**
     * @param  array{title: string, body: string, link_url?: ?string, link_label?: ?string, channels: list<string>, audience: string, audience_options?: array, scheduled_at?: mixed, is_promotion?: bool}  $data
     */
    public function send(array $data, WpUser $admin): Broadcast
    {
        $at = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null;
        $later = $at !== null && $at->isFuture();
        $options = $data['audience_options'] ?? [];
        $promotion = (bool) ($data['is_promotion'] ?? false);

        $broadcast = Broadcast::create([
            ...$data,
            'audience_options' => $options,
            'is_promotion' => $promotion,
            'scheduled_at' => $later ? $at : null,
            'status' => $later ? 'scheduled' : 'sending',
            'started_at' => $later ? null : now(),
            // Who it would reach right now. Sending settles the real number.
            'recipients_count' => $this->audience->count($data['audience'], $options, $promotion),
            'created_by' => $admin->ID,
        ]);

        app(AdminAuditLogService::class)->record($admin, $later ? 'broadcast.scheduled' : 'broadcast.sent', Broadcast::class, $broadcast->id, [
            'title' => $broadcast->title,
            'to' => $this->audience->describe($broadcast->audience, $broadcast->audience_options ?? []),
            'recipients' => $broadcast->recipients_count,
            'channels' => $broadcast->channels,
            'promotion' => $promotion,
            'scheduled_at' => $later ? $at->toDateTimeString() : null,
        ]);

        if (! $later) {
            SendBroadcast::dispatch($broadcast->id);
        }

        return $broadcast;
    }

    /** Scheduled messages whose time has come start sending (run every minute). @return int how many started */
    public function startDue(): int
    {
        $started = 0;

        Broadcast::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->pluck('id')->each(function ($id) use (&$started) {
            // The update only matches once, so two overlapping runs can't both start it.
            if (Broadcast::query()->whereKey($id)->where('status', 'scheduled')->update(['status' => 'sending', 'started_at' => now()])) {
                SendBroadcast::dispatch($id);
                $started++;
            }
        });

        return $started;
    }

    /** Messages that say "sending" but haven't moved for a few minutes are picked up again. @return int how many restarted */
    public function resumeStalled(int $idleMinutes = 4): int
    {
        $ids = Broadcast::query()->where('status', 'sending')->where('updated_at', '<', now()->subMinutes($idleMinutes))->pluck('id');
        $ids->each(fn ($id) => SendBroadcast::dispatch($id));

        return $ids->count();
    }

    /** Stop a message that hasn't finished (not yet started, or part-way). Whoever already got it keeps it. */
    public function cancel(Broadcast $broadcast, WpUser $admin): bool
    {
        $stopped = Broadcast::query()->whereKey($broadcast->id)->whereIn('status', ['scheduled', 'sending', 'failed'])
            ->update(['status' => 'cancelled', 'recipients_count' => DB::raw('delivered_count')]);

        if ($stopped) {
            $this->recordAdminAction($admin, 'broadcast.cancelled', $broadcast, ['delivered_so_far' => $broadcast->fresh()->delivered_count]);
        }

        return (bool) $stopped;
    }

    /** Start a scheduled message now, or carry on with one that hit a problem. */
    public function sendNow(Broadcast $broadcast, WpUser $admin): bool
    {
        $wasFailed = $broadcast->status === 'failed';
        $moved = Broadcast::query()->whereKey($broadcast->id)->whereIn('status', ['scheduled', 'failed'])
            ->update(['status' => 'sending', 'started_at' => $broadcast->started_at ?? now(), 'error' => null]);

        if ($moved) {
            $this->recordAdminAction($admin, $wasFailed ? 'broadcast.resumed' : 'broadcast.sent_early', $broadcast);
            SendBroadcast::dispatch($broadcast->id);
        }

        return (bool) $moved;
    }

    public function reschedule(Broadcast $broadcast, Carbon $at, WpUser $admin): bool
    {
        if (! $at->isFuture()) {
            return false;
        }

        $moved = Broadcast::query()->whereKey($broadcast->id)->where('status', 'scheduled')->update(['scheduled_at' => $at]);

        if ($moved) {
            $this->recordAdminAction($admin, 'broadcast.rescheduled', $broadcast, ['from' => $broadcast->scheduled_at?->toDateTimeString(), 'to' => $at->toDateTimeString()]);
        }

        return (bool) $moved;
    }

    /**
     * Reach the next batch of customers. Everything in one transaction:
     * the "reached" rows, the inbox rows and the queued emails all save
     * together or not at all, so an interrupted batch is simply redone
     * and never half-sent or sent twice.
     *
     * @return bool whether more customers remain
     */
    public function sendNextBatch(Broadcast $broadcast, int $size = 200): bool
    {
        $users = $this->audience->query($broadcast->audience, $broadcast->audience_options ?? [], $broadcast->is_promotion)
            ->where('ID', '>', $broadcast->last_user_id)->orderBy('ID')->limit($size)->get();

        if ($users->isEmpty()) {
            $this->finish($broadcast);

            return false;
        }

        DB::transaction(function () use ($broadcast, $users) {
            $already = DB::table('broadcast_deliveries')->where('broadcast_id', $broadcast->id)->whereIn('user_id', $users->pluck('ID'))->pluck('user_id')->all();
            $fresh = $users->reject(fn (WpUser $u) => in_array($u->ID, $already, true));

            if ($fresh->isNotEmpty()) {
                DB::table('broadcast_deliveries')->insert($fresh->map(fn (WpUser $u) => [
                    'broadcast_id' => $broadcast->id, 'user_id' => $u->ID, 'created_at' => now(),
                ])->all());

                $this->deliver($broadcast, $fresh);
            }

            $broadcast->forceFill([
                'last_user_id' => $users->last()->ID,
                'delivered_count' => $broadcast->delivered_count + $fresh->count(),
            ])->save();
        });

        $more = $users->count() >= $size || $this->audience->query($broadcast->audience, $broadcast->audience_options ?? [], $broadcast->is_promotion)
            ->where('ID', '>', $broadcast->last_user_id)->exists();

        if (! $more) {
            $this->finish($broadcast);
        }

        return $more;
    }

    private function finish(Broadcast $broadcast): void
    {
        $options = $broadcast->audience_options ?? [];

        $broadcast->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'recipients_count' => $broadcast->delivered_count,
            // Customers who match the group but asked not to get promotions.
            'skipped_count' => $broadcast->is_promotion
                ? max(0, $this->audience->count($broadcast->audience, $options) - $this->audience->count($broadcast->audience, $options, true))
                : 0,
        ])->save();
    }

    private function recordAdminAction(WpUser $admin, string $action, Broadcast $broadcast, array $context = []): void
    {
        app(AdminAuditLogService::class)->record($admin, $action, Broadcast::class, $broadcast->id, ['title' => $broadcast->title, ...$context]);
    }

    /**
     * Deliver to one batch of customers. Each copy (site, email, push) is
     * written down in message_deliveries so the message's page can show
     * what was sent, failed, opened and tapped (DeliveryTracker).
     *
     * @param  iterable<WpUser>  $users
     */
    public function deliver(Broadcast $broadcast, iterable $users): void
    {
        $users = collect($users);
        $tracked = $broadcast->id > 0 ? $this->track($broadcast, $users) : [];

        if (in_array('inbox', $broadcast->channels, true)) {
            CustomerMessage::insert($users->map(fn (WpUser $u) => [
                'user_id' => $u->ID,
                'broadcast_id' => $broadcast->id,
                'delivery_id' => $tracked[$u->ID]['inbox']['id'] ?? null,
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

    /**
     * One tracking row per customer per channel. Push only for customers
     * who turned notifications on (nobody else can get one).
     *
     * @param  \Illuminate\Support\Collection<int, WpUser>  $users
     */
    private function track(Broadcast $broadcast, $users): array
    {
        $ids = $users->pluck('ID')->all();
        $withPush = in_array('push', $broadcast->channels, true)
            ? WpUserMeta::query()->whereIn('user_id', $ids)->where('meta_key', 'rk_onesignal_id')->where('meta_value', '!=', '')->pluck('user_id')->map(fn ($id) => (int) $id)->flip()
            : collect();
        $rows = [];

        foreach ($users as $u) {
            foreach ($broadcast->channels as $channel) {
                if ($channel === 'push' && ! isset($withPush[(int) $u->ID])) {
                    continue;
                }
                if ($channel === 'email' && blank($u->user_email)) {
                    continue;
                }

                $rows[] = [
                    'user_id' => (int) $u->ID,
                    'channel' => $channel,
                    'target_url' => $broadcast->link_url ?: ($channel === 'push' ? '/messages' : null),
                ];
            }
        }

        return app(DeliveryTracker::class)->createMany('broadcast', $broadcast->id, $rows);
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

    /**
     * How messages address a customer: their full name (first and last
     * name from their profile), or their username when no name is saved.
     */
    public static function fullName(WpUser $user): string
    {
        $full = trim(trim((string) $user->metaValue('first_name')).' '.trim((string) $user->metaValue('last_name')));

        return $full !== '' ? $full : (string) $user->user_login;
    }

    /** {name} → the customer's full name (or username). */
    public static function personalise(string $text, WpUser $user): string
    {
        return str_replace(['{name}', '{username}'], [self::fullName($user), $user->user_login], $text);
    }

    public static function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? url($url) : $url;
    }
}
