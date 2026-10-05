<?php

namespace App\Services\Admin;

use App\Auth\StaffRoles;
use App\Filament\Pages\FraudWatch;
use App\Filament\Pages\GamingTax;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\BankTransferResource;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\PaymentMismatchResource;
use App\Filament\Resources\RaffleWinnerResource;
use App\Filament\Resources\SupportTicketResource;
use App\Filament\Resources\WinnerStoryResource;
use App\Filament\Resources\WithdrawalRequestResource;
use App\Models\Admin\StaffTask;
use App\Models\Deposit;
use App\Models\Growth\AffiliateEarning;
use App\Models\KnowledgeArticle;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\ReferralCommission;
use App\Models\SupportTicket;
use App\Models\WinnerStory;
use App\Models\WithdrawalRequest;
use App\Services\GamingTaxReminders;
use App\Services\Risk\FraudWatchService;
use App\Support\Formats;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The team to-do list behind the admin bell.
 *
 * sync() looks at every queue that needs staff (withdrawals to pay, bank
 * transfers to check, tickets to answer...) and makes one to-do per waiting
 * thing. Staff can also type in their own. Anyone can tick a to-do off; the
 * list then shows who did it and when, to everyone. When the thing is sorted
 * in its own queue (the withdrawal is paid, the ticket answered) the to-do
 * closes by itself, and if it comes back (the customer writes again) it
 * opens again.
 *
 * Each person only sees to-dos from screens their staff role can open
 * (App\Auth\StaffRoles). This only reads the queues: nothing here pays,
 * approves or changes anything outside the to-do list.
 */
class StaffTodo
{
    /** At most this many new to-dos per queue per check (oldest first). */
    public const PER_SOURCE = 100;

    public const MANUAL = 'manual';

    /** Done to-dos are kept this long, then tidied away. */
    public const KEEP_DONE_DAYS = 90;

    private const SYNCED_KEY = 'staff-todo:synced';

    /**
     * Where each kind of to-do comes from: the label shown with it, its
     * icon, and the admin screen whose access decides who sees it.
     */
    public const SOURCES = [
        'withdrawal' => ['label' => 'Payouts', 'icon' => 'heroicon-o-arrow-up-tray', 'screen' => WithdrawalRequestResource::class],
        'bank_transfer' => ['label' => 'Bank transfers', 'icon' => 'heroicon-o-building-library', 'screen' => BankTransferResource::class],
        'mismatch' => ['label' => 'Payment mismatches', 'icon' => 'heroicon-o-scale', 'screen' => PaymentMismatchResource::class],
        'winner' => ['label' => 'Winners to pay', 'icon' => 'heroicon-o-trophy', 'screen' => RaffleWinnerResource::class],
        'ticket' => ['label' => 'Support', 'icon' => 'heroicon-o-chat-bubble-left-right', 'screen' => SupportTicketResource::class],
        'quick_cashout' => ['label' => 'Fraud watch', 'icon' => 'heroicon-o-shield-exclamation', 'screen' => FraudWatch::class],
        'held_commission' => ['label' => 'Fraud watch', 'icon' => 'heroicon-o-shield-exclamation', 'screen' => FraudWatch::class],
        'affiliate_hold' => ['label' => 'Fraud watch', 'icon' => 'heroicon-o-shield-exclamation', 'screen' => FraudWatch::class],
        'winner_story' => ['label' => 'Winner stories', 'icon' => 'heroicon-o-camera', 'screen' => WinnerStoryResource::class],
        'help_draft' => ['label' => 'Help articles', 'icon' => 'heroicon-o-light-bulb', 'screen' => KnowledgeArticleResource::class],
        'gaming_tax' => ['label' => 'Gaming tax', 'icon' => 'heroicon-o-receipt-percent', 'screen' => GamingTax::class],
        'failed_jobs' => ['label' => 'System health', 'icon' => 'heroicon-o-exclamation-triangle', 'screen' => SystemHealth::class],
        self::MANUAL => ['label' => 'Added by staff', 'icon' => 'heroicon-o-pencil-square', 'screen' => null],
    ];

    // -- Reading the list -------------------------------------------------

    /** @return list<string> the kinds of to-do this person may see */
    public function visibleSources(?WpUser $user): array
    {
        $role = $user?->staffRole();

        if ($role === null) {
            return [];
        }

        return array_keys(array_filter(self::SOURCES, fn ($s) => $s['screen'] === null || StaffRoles::canOpen($role, $s['screen'])));
    }

    /** Every to-do this person may see, open or done. */
    public function query(?WpUser $user): Builder
    {
        return StaffTask::query()->whereIn('source', $this->visibleSources($user) ?: ['-']);
    }

    /** The number on the bell: open to-dos this person can see. */
    public function openCount(?WpUser $user): int
    {
        try {
            return $this->query($user)->open()->count();
        } catch (QueryException) {
            return 0; // table not created yet (mid-deploy)
        }
    }

    /** @return Collection<int, StaffTask> open to-dos, longest-waiting first */
    public function open(?WpUser $user, int $limit = 8): Collection
    {
        return $this->query($user)->open()->orderByRaw('COALESCE(waiting_since, created_at)')->orderBy('id')->limit($limit)->get();
    }

    /** @return Collection<int, StaffTask> ticked off in the last day, newest first */
    public function recentlyDone(?WpUser $user, int $limit = 3): Collection
    {
        return $this->query($user)->done()->where('done_at', '>=', now()->subDay())->latest('done_at')->limit($limit)->get();
    }

    public static function label(StaffTask $task): string
    {
        return self::SOURCES[$task->source]['label'] ?? 'Other';
    }

    public static function icon(StaffTask $task): string
    {
        return self::SOURCES[$task->source]['icon'] ?? 'heroicon-o-bell';
    }

    /** Where clicking the to-do takes you: the queue it came from, or the ticket itself. */
    public static function url(StaffTask $task): ?string
    {
        try {
            return match ($task->source) {
                'ticket' => SupportTicketResource::getUrl('view', ['record' => $task->source_key]),
                self::MANUAL => $task->url,
                default => ($screen = self::SOURCES[$task->source]['screen'] ?? null) ? $screen::getUrl() : null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    // -- Ticking off --------------------------------------------------------

    public function markDone(StaffTask $task, WpUser $by, ?string $note = null): StaffTask
    {
        if (! $task->isDone()) {
            $task->update([
                'done_at' => now(),
                'done_by' => $by->ID,
                'done_by_name' => Str::limit($by->display_name ?: $by->user_login, 97),
                'done_note' => filled($note) ? Str::limit(trim($note), 297) : null,
            ]);
        }

        return $task;
    }

    /** Untick a to-do a person ticked (one sorted in its queue stays closed). */
    public function reopen(StaffTask $task): StaffTask
    {
        if ($task->isDone() && $task->cleared_at === null) {
            $task->update(['done_at' => null, 'done_by' => null, 'done_by_name' => null, 'done_note' => null]);
        }

        return $task;
    }

    public function add(string $title, ?string $detail, WpUser $by, ?string $url = null): StaffTask
    {
        return StaffTask::create([
            'source' => self::MANUAL,
            'source_key' => (string) Str::uuid(),
            'title' => Str::limit(trim($title), 197),
            'detail' => filled($detail) ? Str::limit(trim($detail), 297) : null,
            'url' => filled($url) ? Str::limit(trim($url), 497, '') : null,
            'waiting_since' => now(),
            'created_by' => $by->ID,
        ]);
    }

    /** Can this person tick it off / untick it? Only to-dos they can see. */
    public function canTouch(?WpUser $user, StaffTask $task): bool
    {
        return in_array($task->source, $this->visibleSources($user), true);
    }

    // -- Keeping the list up to date --------------------------------------

    /** Sync at most once a minute, however many staff have the admin open. */
    public function syncIfDue(): void
    {
        if (Cache::add(self::SYNCED_KEY, 1, 60)) {
            $this->sync();
        }
    }

    /** Bring the list up to date with every queue. One broken queue never stops the others. */
    public function sync(): void
    {
        Cache::put(self::SYNCED_KEY, 1, 60);

        Cache::lock('staff-todo:sync', 120)->get(function () {
            foreach ($this->feeds() as $source => $feed) {
                try {
                    $this->syncSource($source, $feed);
                } catch (Throwable $e) {
                    // Once an hour per queue at most: this runs every minute.
                    if (Cache::add("staff-todo:failed:{$source}", 1, 3600)) {
                        report($e);
                    }
                }
            }
        });
    }

    /** Remove to-dos that were done and sorted long ago. */
    public function prune(): int
    {
        return StaffTask::query()->done()->where('done_at', '<', now()->subDays(self::KEEP_DONE_DAYS))
            ->where(fn ($q) => $q->whereNotNull('cleared_at')->orWhere('source', self::MANUAL))
            ->delete();
    }

    /** @param array{items: Closure(): list<array>, still: Closure(list<string>): list<string>} $feed */
    private function syncSource(string $source, array $feed): void
    {
        $items = collect(($feed['items'])())->keyBy('key');
        $existing = $items->isEmpty() ? collect() : StaffTask::query()->where('source', $source)
            ->whereIn('source_key', $items->keys()->all())->get()->keyBy('source_key');

        foreach ($items as $key => $item) {
            $fields = [
                'title' => Str::limit($item['title'], 197),
                'detail' => filled($item['detail'] ?? null) ? Str::limit($item['detail'], 297) : null,
                'waiting_since' => $item['since'] ?? null,
            ];
            $task = $existing->get($key);

            if (! $task) {
                try {
                    StaffTask::create(['source' => $source, 'source_key' => (string) $key] + $fields);
                } catch (QueryException) {
                    // Someone else's check added it a moment ago.
                }
            } elseif ($task->cleared_at !== null) {
                // It was sorted, and now it is waiting again (e.g. the customer wrote back).
                $task->update($fields + ['done_at' => null, 'done_by' => null, 'done_by_name' => null, 'done_note' => null, 'cleared_at' => null]);
            } elseif (! $task->isDone()) {
                $task->fill($fields)->isDirty() && $task->save();
            }
        }

        // To-dos whose thing may no longer be waiting. Ask the queue about
        // those exact records, so a long queue (beyond PER_SOURCE) never
        // closes a to-do by mistake.
        StaffTask::query()->where('source', $source)->whereNull('cleared_at')
            ->whereNotIn('source_key', $items->keys()->all() ?: ['-'])
            ->select(['id', 'source_key', 'done_at'])
            ->chunkById(500, function ($tasks) use ($feed) {
                $still = array_flip(($feed['still'])($tasks->pluck('source_key')->all()));
                $gone = $tasks->reject(fn ($t) => isset($still[$t->source_key]));

                if ($gone->isEmpty()) {
                    return;
                }

                $now = now();
                StaffTask::query()->whereIn('id', $gone->pluck('id'))->whereNull('done_at')->update(['done_at' => $now, 'cleared_at' => $now]);
                StaffTask::query()->whereIn('id', $gone->pluck('id'))->whereNull('cleared_at')->update(['cleared_at' => $now]);
            });
    }

    /** @return array<string, array{items: Closure, still: Closure}> */
    private function feeds(): array
    {
        $name = fn ($user) => $user ? ($user->display_name ?: $user->user_login) : 'a customer';
        $naira = fn ($amount) => Formats::naira($amount);

        return [
            'withdrawal' => $this->rows(
                WithdrawalRequest::query()->with('user')->where('status', 'pending'),
                fn (WithdrawalRequest $w) => ['title' => 'Pay '.$naira($w->amount_to_send ?? $w->requested_amount).' withdrawal to '.$name($w->user)],
            ),
            'bank_transfer' => $this->rows(
                RaffleTransaction::query()->with('user')->whereIn('type', ['wallet_deposit', 'deposit_manual', 'ticket_purchase'])->whereIn('status', ['pending', 'manual_review']),
                fn (RaffleTransaction $t) => ['title' => 'Check '.$naira($t->claimed_amount).' bank transfer from '.$name($t->user)],
            ),
            'mismatch' => $this->rows(
                Deposit::query()->with('user')->where('status', 'amount_mismatch'),
                fn (Deposit $d) => ['title' => 'Sort out '.$naira($d->amount).' payment that did not match ('.$name($d->user).')'],
            ),
            'winner' => $this->rows(
                RaffleWinner::query()->with('user')->where('is_credited', false),
                fn (RaffleWinner $w) => ['title' => 'Pay '.$name($w->user).'\'s prize: '.$w->prize_name],
                'won_at',
            ),
            'ticket' => $this->rows(
                SupportTicket::query()->with('user')->where('status', 'open'),
                fn (SupportTicket $t) => [
                    'title' => 'Answer '.$name($t->user).': "'.Str::limit((string) $t->subject, 80).'"',
                    'detail' => $t->needs_human ? 'The support assistant handed this one to the team.' : null,
                ],
                'updated_at',
            ),
            'quick_cashout' => $this->listed(fn () => app(FraudWatchService::class)->quickCashOuts(30, pendingOnly: true)
                ->map(fn ($f) => ['key' => (string) $f['withdrawal_id'], 'title' => 'Check a quick cash-out before paying it', 'detail' => $f['reason'] ?? null])
                ->values()->all()),
            'held_commission' => $this->rows(
                ReferralCommission::query()->where('status', 'held'),
                fn (ReferralCommission $c) => ['title' => 'Check a held referral reward of '.$naira($c->commission_amount)],
            ),
            'affiliate_hold' => $this->rows(
                AffiliateEarning::query()->where('status', 'on_hold'),
                fn (AffiliateEarning $e) => ['title' => 'Check an affiliate earning on hold ('.$naira($e->commission).')', 'detail' => $e->note],
            ),
            'winner_story' => $this->rows(
                WinnerStory::query()->where('status', 'pending'),
                fn (WinnerStory $s) => ['title' => 'Approve or decline a winner story', 'detail' => filled($s->caption) ? '"'.Str::limit($s->caption, 100).'"' : null],
            ),
            'help_draft' => $this->rows(
                KnowledgeArticle::query()->whereNotNull('suggested_from_ticket_id')->where('is_active', false),
                fn (KnowledgeArticle $a) => ['title' => 'Review the suggested help article "'.Str::limit($a->title, 80).'"'],
            ),
            'gaming_tax' => $this->listed(fn () => collect(Cache::remember('staff-todo:gaming-tax', 600, fn () => app(GamingTaxReminders::class)->attention()))
                ->reject(fn ($a) => $a['severity'] === 'info')
                ->map(fn ($a) => ['key' => $a['period'].':'.$a['status'], 'title' => 'Gaming tax, '.$a['headline'], 'detail' => $a['next_step']])
                ->values()->all()),
            'failed_jobs' => $this->listed(fn () => DB::table(config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '>=', now()->subDay())->pluck('failed_at')
                ->groupBy(fn ($at) => Carbon::parse($at)->toDateString())
                ->map(fn ($day, $date) => [
                    'key' => $date,
                    'title' => count($day).' background '.Str::plural('job', count($day)).' failed on '.Carbon::parse($date)->format('j M'),
                    'detail' => 'Some emails or alerts may not have been sent. See System health.',
                    'since' => Carbon::parse($day->min()),
                ])->values()->all()),
        ];
    }

    /** A queue of database records: one to-do per record. */
    private function rows(Builder $query, Closure $describe, string $since = 'created_at'): array
    {
        $key = $query->getModel()->getKeyName();

        return [
            'items' => fn () => (clone $query)->orderBy($since)->limit(self::PER_SOURCE)->get()
                ->map(fn ($r) => ['key' => (string) $r->getKey(), 'since' => $r->{$since}] + $describe($r))->all(),
            'still' => fn (array $keys) => (clone $query)->whereIn($key, $keys)->pluck($key)->map(fn ($k) => (string) $k)->all(),
        ];
    }

    /** A short list worked out in full each time (fraud flags, tax months, failed jobs). */
    private function listed(Closure $all): array
    {
        $items = null;
        $get = function () use (&$items, $all) {
            return $items ??= $all();
        };

        return [
            'items' => $get,
            'still' => fn (array $keys) => array_values(array_intersect($keys, array_column($get(), 'key'))),
        ];
    }
}
