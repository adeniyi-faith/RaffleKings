<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BankTransferResource;
use App\Filament\Resources\PaymentMismatchResource;
use App\Filament\Resources\RaffleWinnerResource;
use App\Filament\Resources\SupportTicketResource;
use App\Filament\Resources\WithdrawalRequestResource;
use App\Models\Deposit;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\SupportTicket;
use App\Models\WithdrawalRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OVERHAUL_CHECKLIST.md item 45 — everything waiting for staff, first
 * thing on the dashboard. Each box opens its queue; it turns orange when
 * something is waiting, and shows how long the oldest item has waited.
 */
class NeedsAttention extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Needs attention';

    protected static ?string $pollingInterval = '60s';

    private function box(string $label, $query, string $url, string $dateColumn = 'created_at'): Stat
    {
        $count = (clone $query)->count();
        $oldest = $count > 0 ? (clone $query)->min($dateColumn) : null;

        return Stat::make($label, (string) $count)
            ->description($count === 0 ? 'All clear' : 'Oldest waiting '.Carbon::parse($oldest)->diffForHumans(null, true))
            ->descriptionIcon($count === 0 ? 'heroicon-m-check-circle' : 'heroicon-m-clock')
            ->color($count === 0 ? 'success' : 'warning')
            ->url($url);
    }

    protected function getStats(): array
    {
        $stats = [
            $this->box('Withdrawals to pay', WithdrawalRequest::query()->where('status', 'pending'), WithdrawalRequestResource::getUrl()),
            $this->box('Bank transfers to review', RaffleTransaction::query()->whereIn('type', ['wallet_deposit', 'deposit_manual', 'ticket_purchase'])->whereIn('status', ['pending', 'manual_review']), BankTransferResource::getUrl()),
            $this->box('Payment mismatches', Deposit::query()->where('status', 'amount_mismatch'), PaymentMismatchResource::getUrl()),
            $this->box('Winners to pay', RaffleWinner::query()->where('is_credited', false), RaffleWinnerResource::getUrl(), 'won_at'),
            $this->box('Support tickets waiting', SupportTicket::query()->where('status', 'open'), SupportTicketResource::getUrl(), 'updated_at'),
        ];

        try {
            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->where('failed_at', '>=', now()->subDay())->count();
            $stats[] = Stat::make('Failed background jobs (24h)', (string) $failed)
                ->description($failed === 0 ? 'Emails & alerts sending fine' : 'Some emails/alerts failed. See System → Health.')
                ->color($failed === 0 ? 'success' : 'danger');
        } catch (Throwable) {
            // No failed_jobs table yet — nothing to show.
        }

        return $stats;
    }
}
