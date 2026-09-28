<?php

namespace App\Filament\Resources\Legacy\WpUserResource\RelationManagers;

use App\Filament\Support\LedgerReasons;
use App\Filament\Support\MobileCard;
use App\Models\WalletLedgerEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Every naira in or out of this customer's wallet and winnings. */
class MoneyMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Money';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public function isReadOnly(): bool
    {
        return true;
    }

    private static function signed(WalletLedgerEntry $e): string
    {
        return ($e->direction === 'credit' ? '+' : '−').'₦'.number_format((float) $e->amount, 2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                MobileCard::make(fn (WalletLedgerEntry $e) => [
                    'title' => LedgerReasons::label($e->reason),
                    'amount' => static::signed($e),
                    'lines' => [$e->description],
                    'badges' => [[$e->balance_type === 'earnings' ? 'Winnings' : 'Wallet', $e->direction === 'credit' ? 'success' : 'gray']],
                    'meta' => $e->created_at?->format('j M Y, H:i'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('j M Y, H:i')->sortable(),
                    Tables\Columns\TextColumn::make('reason')->label('What')->formatStateUsing(fn ($state) => LedgerReasons::label($state))->description(fn (WalletLedgerEntry $e) => $e->description)->wrap(),
                    Tables\Columns\TextColumn::make('balance_type')->label('Balance')->badge()->formatStateUsing(fn ($state) => $state === 'earnings' ? 'Winnings' : 'Wallet')->color('gray'),
                    Tables\Columns\TextColumn::make('amount')->state(fn (WalletLedgerEntry $e) => static::signed($e))->color(fn (WalletLedgerEntry $e) => $e->direction === 'credit' ? 'success' : null)->weight('bold')->alignEnd(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('balance_type')->label('Balance')->options(['wallet' => 'Wallet', 'earnings' => 'Winnings']),
                Tables\Filters\SelectFilter::make('reason')->label('What')->options(LedgerReasons::LABELS),
            ])
            ->emptyStateHeading('No money movements yet');
    }
}
