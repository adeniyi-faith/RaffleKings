<?php

namespace App\Services\Admin;

use App\Filament\Support\LedgerReasons;
use App\Models\Admin\CustomerNote;
use App\Models\Admin\LoginEvent;
use App\Models\AdminAuditLog;
use App\Models\CustomerMessage;
use App\Models\Deposit;
use App\Models\Growth\PromoRedemption;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\PointLedgerEntry;
use App\Models\Raffle;
use App\Models\SupportTicket;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use App\Support\Formats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Everything that happened to one customer, newest first, on their admin
 * page: money in and out, tickets, wins, withdrawals, points, support,
 * sign-ins, messages they were sent, promo codes, staff actions and staff
 * notes. Support can answer "what happened to my money?" by reading down
 * one list instead of opening six tabs.
 *
 * Each source is read with its own cap (the newest SOURCE_LIMIT rows), so
 * the page stays fast for customers with years of history.
 */
final class CustomerTimeline
{
    public const KINDS = [
        'money' => 'Money',
        'tickets' => 'Tickets & wins',
        'withdrawals' => 'Withdrawals',
        'points' => 'Points',
        'support' => 'Support & messages',
        'logins' => 'Sign-ins',
        'staff' => 'Staff actions & notes',
    ];

    private const SOURCE_LIMIT = 300;

    /**
     * @param  string|null  $kind  one of KINDS, or null for everything
     * @return list<array{at: Carbon, kind: string, icon: string, color: string, title: string, detail: ?string, amount: ?string}>
     */
    public function for(WpUser $user, ?string $kind = null, int $limit = 50): array
    {
        $sources = [
            'money' => fn () => $this->money($user->ID),
            'tickets' => fn () => $this->tickets($user->ID)->merge($this->wins($user->ID)),
            'withdrawals' => fn () => $this->withdrawals($user->ID),
            'points' => fn () => $this->points($user->ID),
            'support' => fn () => $this->support($user->ID)->merge($this->messages($user->ID)),
            'logins' => fn () => $this->logins($user->ID),
            'staff' => fn () => $this->staff($user->ID)->merge($this->notes($user->ID)),
        ];

        $events = collect();

        foreach ($sources as $name => $source) {
            if ($kind !== null && $kind !== $name) {
                continue;
            }

            try {
                $events = $events->merge($source());
            } catch (Throwable $e) {
                report($e); // one broken source never hides the rest
            }
        }

        return $events->filter(fn ($e) => $e['at'] !== null)->sortByDesc(fn ($e) => $e['at']->getTimestamp())->take($limit)->values()->all();
    }

    private function event(mixed $at, string $kind, string $icon, string $color, string $title, ?string $detail = null, ?string $amount = null): array
    {
        return [
            'at' => $at ? Carbon::parse($at) : null,
            'kind' => $kind,
            'icon' => $icon,
            'color' => $color,
            'title' => $title,
            'detail' => $detail,
            'amount' => $amount,
        ];
    }

    private function money(int $userId): Collection
    {
        return WalletLedgerEntry::query()->where('user_id', $userId)
            // Ticket purchases are shown with their numbers under "tickets".
            ->where('reason', '!=', 'ticket_purchase')
            ->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->map(fn (WalletLedgerEntry $e) => $this->event(
                $e->created_at,
                'money',
                $e->direction === 'credit' ? 'heroicon-m-arrow-down-circle' : 'heroicon-m-arrow-up-circle',
                $e->direction === 'credit' ? 'success' : 'warning',
                LedgerReasons::label($e->reason),
                trim(($e->balance_type === 'earnings' ? 'Winnings' : 'Spending wallet').($e->description ? ' · '.$e->description : '')),
                ($e->direction === 'credit' ? '+' : '−').Formats::naira($e->amount),
            ))
            ->merge(Deposit::query()->where('user_id', $userId)->whereIn('status', ['failed', 'amount_mismatch', 'pending'])
                ->where('created_at', '>=', now()->subDays(90))->latest('id')->limit(50)->get()
                ->map(fn (Deposit $d) => $this->event(
                    $d->created_at,
                    'money',
                    'heroicon-m-exclamation-circle',
                    $d->status === 'pending' ? 'gray' : 'danger',
                    match ($d->status) {
                        'failed' => 'Top-up failed',
                        'amount_mismatch' => 'Top-up paid with a different amount',
                        default => 'Top-up started, not finished',
                    },
                    ucfirst((string) $d->gateway).($d->failure_reason ? ' · '.$d->failure_reason : ''),
                    Formats::naira($d->amount),
                )));
    }

    private function tickets(int $userId): Collection
    {
        $entries = RaffleEntry::query()->where('user_id', $userId)->latest('created_at')->limit(1000)->get(['raffle_id', 'ticket_number', 'txn_id', 'created_at']);
        $titles = Raffle::query()->whereIn('public_id', $entries->pluck('raffle_id')->unique())->pluck('title', 'public_id');
        $paid = RaffleTransaction::query()->whereIn('id', $entries->pluck('txn_id')->filter()->unique())->pluck('claimed_amount', 'id');

        return $entries->groupBy(fn ($e) => $e->txn_id.'-'.$e->raffle_id)->take(self::SOURCE_LIMIT)->map(function (Collection $group) use ($titles, $paid) {
            $first = $group->first();
            $numbers = $group->pluck('ticket_number')->sort()->values();

            return $this->event(
                $group->min('created_at'),
                'tickets',
                'heroicon-m-ticket',
                'info',
                $group->count().' ticket'.($group->count() === 1 ? '' : 's').' in '.($titles[$first->raffle_id] ?? "raffle #{$first->raffle_id}"),
                'Numbers '.$numbers->take(15)->implode(', ').($numbers->count() > 15 ? '…' : ''),
                isset($paid[$first->txn_id]) ? '−'.Formats::naira($paid[$first->txn_id]) : null,
            );
        })->values();
    }

    private function wins(int $userId): Collection
    {
        $wins = RaffleWinner::query()->where('user_id', $userId)->latest('won_at')->limit(self::SOURCE_LIMIT)->get();
        $titles = Raffle::query()->whereIn('public_id', $wins->pluck('raffle_id')->unique())->pluck('title', 'public_id');

        return $wins->map(fn (RaffleWinner $w) => $this->event(
            $w->won_at,
            'tickets',
            'heroicon-m-trophy',
            'success',
            'Won '.$w->prize_name,
            'Ticket #'.$w->ticket_number.' in '.($titles[$w->raffle_id] ?? "raffle #{$w->raffle_id}").($w->is_credited ? ' · paid' : ' · not paid yet'),
            (float) $w->prize_cash_value > 0 ? Formats::naira($w->prize_cash_value) : null,
        ));
    }

    private function withdrawals(int $userId): Collection
    {
        return WithdrawalRequest::query()->with('bankAccount')->where('user_id', $userId)->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->flatMap(function (WithdrawalRequest $w) {
                $to = $w->bankAccount ? "{$w->bankAccount->bank_name} {$w->bankAccount->account_number}" : 'no bank account';
                $rows = [$this->event($w->created_at, 'withdrawals', 'heroicon-m-arrow-up-tray', 'warning', 'Asked to withdraw', "To {$to}", Formats::naira($w->amount_to_send))];

                if ($w->status !== 'pending') {
                    $rows[] = $this->event(
                        $w->updated_at,
                        'withdrawals',
                        $w->status === 'paid' ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle',
                        $w->status === 'paid' ? 'success' : 'danger',
                        $w->status === 'paid' ? 'Withdrawal paid'.($w->payout_status === 'success' ? ' (by Paystack)' : '') : 'Withdrawal rejected and refunded',
                        "Withdrawal #{$w->id} to {$to}",
                        Formats::naira($w->amount_to_send),
                    );
                } elseif ($w->payout_status === 'failed' && $w->payout_error) {
                    $rows[] = $this->event($w->updated_at, 'withdrawals', 'heroicon-m-exclamation-triangle', 'danger', 'Paystack could not send it', $w->payout_error);
                }

                return $rows;
            });
    }

    private function points(int $userId): Collection
    {
        return PointLedgerEntry::query()->where('user_id', $userId)->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->map(fn (PointLedgerEntry $p) => $this->event(
                $p->created_at,
                'points',
                'heroicon-m-star',
                $p->direction === 'credit' ? 'success' : 'gray',
                ucfirst(str_replace('_', ' ', (string) $p->reason)),
                $p->description,
                ($p->direction === 'credit' ? '+' : '−').number_format($p->amount).' pts',
            ))
            ->merge(PromoRedemption::query()->with('promoCode')->where('user_id', $userId)->latest('id')->limit(50)->get()
                ->map(fn (PromoRedemption $r) => $this->event(
                    $r->created_at,
                    'points',
                    'heroicon-m-tag',
                    'primary',
                    'Used promo code '.($r->promoCode?->code ?? '#'.$r->promo_code_id),
                    $r->context === 'signup' ? 'At sign-up' : 'At checkout',
                    $r->value > 0 ? Formats::naira($r->value) : null,
                )));
    }

    private function support(int $userId): Collection
    {
        return SupportTicket::query()->where('user_id', $userId)->latest('id')->limit(100)->get()
            ->map(fn (SupportTicket $t) => $this->event($t->created_at, 'support', 'heroicon-m-lifebuoy', 'info', 'Opened support ticket: '.$t->subject, 'Ticket #'.$t->id.' · '.$t->status));
    }

    private function messages(int $userId): Collection
    {
        return CustomerMessage::query()->where('user_id', $userId)->latest('id')->limit(100)->get()
            ->map(fn (CustomerMessage $m) => $this->event($m->created_at, 'support', 'heroicon-m-bell', 'gray', 'Sent a message: '.$m->title, $m->read_at ? 'Read '.$m->read_at->diffForHumans() : 'Not read yet'));
    }

    private function logins(int $userId): Collection
    {
        return LoginEvent::query()->where('user_id', $userId)->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->map(fn (LoginEvent $l) => $this->event(
                $l->created_at,
                'logins',
                $l->success ? 'heroicon-m-key' : 'heroicon-m-lock-closed',
                $l->success ? 'gray' : 'danger',
                ($l->success ? 'Signed in' : 'Sign-in refused').($l->place === 'admin' ? ' to the admin' : ''),
                trim($l->deviceName().' · '.$l->ip.($l->reason ? ' · '.str_replace('_', ' ', $l->reason) : '')),
            ));
    }

    private function staff(int $userId): Collection
    {
        return AdminAuditLog::query()->with('admin')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', WpUser::class)->where('subject_id', $userId))->orWhere('context->user_id', $userId))
            ->latest('id')->limit(self::SOURCE_LIMIT)->get()
            ->map(fn (AdminAuditLog $a) => $this->event(
                $a->created_at,
                'staff',
                'heroicon-m-shield-check',
                'primary',
                'Staff: '.str_replace(['.', '_'], [' → ', ' '], $a->action),
                'by '.($a->admin?->display_name ?: $a->admin?->user_login ?: 'system'),
            ));
    }

    private function notes(int $userId): Collection
    {
        return CustomerNote::query()->with('author')->where('user_id', $userId)->latest('id')->limit(100)->get()
            ->map(fn (CustomerNote $n) => $this->event($n->created_at, 'staff', 'heroicon-m-pencil-square', 'warning', 'Staff note'.($n->pinned ? ' (pinned)' : ''), mb_strimwidth($n->body, 0, 300, '…').' — '.($n->author?->display_name ?: 'staff')));
    }
}
