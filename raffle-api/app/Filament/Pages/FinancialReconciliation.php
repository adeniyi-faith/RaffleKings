<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Models\Wallet;
use App\Services\WalletLedgerService;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Answers the question a real reconciliation tool exists to answer: does
 * every wallet's fast-read balance (the `wallets` table) still match what
 * its own permanent ledger says it should be? WalletLedgerService's
 * append-only entries are the source of truth — this page just sums them
 * per user (WalletLedgerService::reconstructBalance()) and flags any row
 * where the stored balance has drifted from that sum, which should never
 * happen if every mutation went through the single settlement paths this
 * app enforces (TicketPurchaseService, WithdrawalService, DepositService,
 * ReferralCommissionService, PointRedemptionService) — a mismatch here
 * means something wrote to `wallets` outside of one of those.
 */
class FinancialReconciliation extends Page implements HasTable
{
    use GuardedByStaffRole, InteractsWithTable;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static string $view = 'filament.pages.financial-reconciliation';

    public function table(Table $table): Table
    {
        $ledger = app(WalletLedgerService::class);

        return $table
            ->query(Wallet::query())
            ->columns([
                Tables\Columns\TextColumn::make('user_id')->label('User ID')->sortable(),
                Tables\Columns\TextColumn::make('wallet_balance')->label('Wallet (stored)')->naira(),
                Tables\Columns\TextColumn::make('wallet_reconciled')
                    ->label('Wallet (ledger)')
                    ->state(fn (Wallet $record) => $ledger->reconstructBalance($record->user_id, 'wallet'))
                    ->naira(),
                Tables\Columns\TextColumn::make('earnings_balance')->label('Earnings (stored)')->naira(),
                Tables\Columns\TextColumn::make('earnings_reconciled')
                    ->label('Earnings (ledger)')
                    ->state(fn (Wallet $record) => $ledger->reconstructBalance($record->user_id, 'earnings'))
                    ->naira(),
                Tables\Columns\IconColumn::make('drift')
                    ->label('Matches?')
                    ->boolean()
                    ->state(function (Wallet $record) use ($ledger) {
                        $walletMatches = round((float) $record->wallet_balance, 2) === round($ledger->reconstructBalance($record->user_id, 'wallet'), 2);
                        $earningsMatches = round((float) $record->earnings_balance, 2) === round($ledger->reconstructBalance($record->user_id, 'earnings'), 2);

                        return $walletMatches && $earningsMatches;
                    }),
            ])
            ->defaultSort('user_id');
    }
}
