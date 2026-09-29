<?php

namespace App\Services\Engagement;

use App\Events\RedEnvelopeUpdated;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Models\RedEnvelope;
use App\Models\RedEnvelopeClaim;
use App\Services\PointsService;
use App\Support\Live;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Red envelopes (Phase 11), like WeChat's: a customer (or the site, from
 * the admin) drops points into a live-draw chat; the first people to tap
 * split them in random shares. Points only — never money. Whatever isn't
 * claimed before it expires goes back to the sender.
 */
class RedEnvelopes
{
    public function __construct(private readonly PointsService $points, private readonly BadgeService $badges) {}

    public function send(?WpUser $sender, Raffle $raffle, int $total, int $slots, ?string $message = null): RedEnvelope
    {
        $min = max(1, (int) config('engagement.red_envelopes.min_points', 50));
        $max = max($min, (int) config('engagement.red_envelopes.max_points', 5000));
        $maxSlots = max(1, (int) config('engagement.red_envelopes.max_slots', 10));

        if ($sender) {
            if ($total < $min || $total > $max) {
                throw new InvalidArgumentException("A red envelope holds between {$min} and {$max} points.");
            }
            if ($slots < 1 || $slots > $maxSlots) {
                throw new InvalidArgumentException("Choose between 1 and {$maxSlots} people.");
            }
        } else {
            $slots = max(1, min(50, $slots)); // the site can drop bigger ones
        }

        if ($total < $slots) {
            throw new InvalidArgumentException('Put in at least one point for each person.');
        }

        $message = $message !== null ? mb_substr(trim(strip_tags($message)), 0, 80) : null;

        $envelope = DB::transaction(function () use ($sender, $raffle, $total, $slots, $message) {
            if ($sender) {
                $this->points->debit($sender, $total, 'red_envelope_sent', 'raffle', $raffle->id, 'Red envelope in the live-draw chat');
            }

            return RedEnvelope::create([
                'raffle_id' => $raffle->id,
                'sender_user_id' => $sender?->ID,
                'message' => $message ?: null,
                'total_points' => $total,
                'slots' => $slots,
                'amounts' => $this->split($total, $slots),
                'expires_at' => now()->addMinutes(max(1, (int) config('engagement.red_envelopes.minutes', 10))),
            ]);
        });

        if ($sender) {
            $this->badges->award($sender->ID, 'generous');
        }

        Live::send(new RedEnvelopeUpdated($raffle->id, 'dropped', $this->present($envelope)));

        return $envelope;
    }

    /** Grabs a share. Returns the points won. */
    public function claim(WpUser $user, RedEnvelope $envelope): int
    {
        if ($envelope->sender_user_id === $user->ID) {
            throw new InvalidArgumentException('You can\'t open your own red envelope.');
        }

        $points = DB::transaction(function () use ($user, $envelope) {
            $locked = RedEnvelope::query()->whereKey($envelope->id)->lockForUpdate()->first();

            if (! $locked->isOpen()) {
                throw new InvalidArgumentException($locked->expires_at->isPast() ? 'This red envelope has expired.' : 'Too slow! This red envelope is empty.');
            }

            $amounts = $locked->amounts;
            $points = (int) array_shift($amounts);

            try {
                RedEnvelopeClaim::create(['red_envelope_id' => $locked->id, 'user_id' => $user->ID, 'points' => $points]);
            } catch (UniqueConstraintViolationException) {
                throw new InvalidArgumentException('You already opened this one.');
            }

            $locked->update(['amounts' => $amounts, 'claimed_count' => $locked->claimed_count + 1]);
            $this->points->credit($user, $points, 'red_envelope', 'red_envelope', $locked->id, 'Red envelope from the live-draw chat');

            return $points;
        });

        $fresh = $envelope->fresh();
        Live::send(new RedEnvelopeUpdated($fresh->raffle_id, 'claimed', $this->present($fresh) + [
            'claimer' => $this->name($user),
            'points' => $points,
        ]));

        return $points;
    }

    /** Envelopes still open in a room, for viewers who arrive late. */
    public function open(int $raffleId, ?int $viewerId = null): array
    {
        $envelopes = RedEnvelope::query()->where('raffle_id', $raffleId)->where('expires_at', '>', now())->whereColumn('claimed_count', '<', 'slots')->latest('id')->limit(5)->get();
        $mine = $viewerId ? RedEnvelopeClaim::query()->where('user_id', $viewerId)->whereIn('red_envelope_id', $envelopes->pluck('id'))->pluck('points', 'red_envelope_id') : collect();

        return $envelopes->map(fn ($e) => $this->present($e) + ['your_points' => $mine[$e->id] ?? null, 'is_yours' => $viewerId !== null && $e->sender_user_id === $viewerId])->values()->all();
    }

    /** Gives unclaimed points back to senders once envelopes expire (runs every minute). */
    public function refundExpired(): int
    {
        $count = 0;

        RedEnvelope::query()->whereNull('refunded_at')->where('expires_at', '<=', now())->each(function (RedEnvelope $e) use (&$count) {
            DB::transaction(function () use ($e, &$count) {
                $locked = RedEnvelope::query()->whereKey($e->id)->lockForUpdate()->first();

                if ($locked->refunded_at) {
                    return;
                }

                $left = array_sum((array) $locked->amounts);
                $locked->update(['refunded_at' => now(), 'amounts' => []]);

                if ($left > 0 && $locked->sender_user_id && ($sender = WpUser::find($locked->sender_user_id))) {
                    $this->points->credit($sender, $left, 'red_envelope_refund', 'red_envelope', $locked->id, 'Unclaimed red envelope points returned');
                    $count++;
                }
            });
        });

        return $count;
    }

    /**
     * A random split like WeChat's: every share at least 1 point, no share
     * more than twice the average of what's left, so it's exciting but fair.
     *
     * @return list<int>
     */
    public function split(int $total, int $slots): array
    {
        $shares = [];
        $left = $total;

        for ($i = $slots; $i > 1; $i--) {
            $maxShare = max(1, min($left - ($i - 1), intdiv(2 * $left, $i)));
            $share = random_int(1, $maxShare);
            $shares[] = $share;
            $left -= $share;
        }

        $shares[] = $left;
        shuffle($shares);

        return $shares;
    }

    private function present(RedEnvelope $e): array
    {
        return [
            'id' => $e->id,
            'sender' => $e->sender_user_id ? $this->name(WpUser::find($e->sender_user_id)) : 'RaffleKings',
            'sender_id' => $e->sender_user_id,
            'message' => $e->message,
            'total_points' => $e->total_points,
            'slots' => $e->slots,
            'claimed_count' => $e->claimed_count,
            'expires_at' => $e->expires_at->toIso8601String(),
        ];
    }

    private function name(?WpUser $user): string
    {
        $name = trim((string) ($user?->display_name ?: $user?->user_login));

        return $name === '' ? 'Someone' : explode(' ', $name)[0];
    }
}
