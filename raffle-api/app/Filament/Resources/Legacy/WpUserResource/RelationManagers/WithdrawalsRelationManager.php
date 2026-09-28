<?php

namespace App\Filament\Resources\Legacy\WpUserResource\RelationManagers;

use App\Filament\Support\MobileCard;
use App\Models\WithdrawalRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class WithdrawalsRelationManager extends RelationManager
{
    protected static string $relationship = 'withdrawalRequests';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Withdrawals';

    protected static ?string $icon = 'heroicon-o-arrow-up-tray';

    public function isReadOnly(): bool
    {
        return true;
    }

    private static function status(WithdrawalRequest $w): array
    {
        return match ($w->status) {
            'pending' => ['Waiting to be paid', 'warning'],
            'paid' => ['Paid', 'success'],
            default => ['Rejected & refunded', 'gray'],
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('bankAccount'))
            ->columns([
                MobileCard::make(fn (WithdrawalRequest $w) => [
                    'title' => $w->bankAccount ? "{$w->bankAccount->bank_name} · {$w->bankAccount->account_number}" : 'No bank account',
                    'amount' => '₦'.number_format((float) $w->amount_to_send),
                    'lines' => [(float) $w->fee_amount > 0 ? 'Asked for ₦'.number_format((float) $w->requested_amount).' · fee ₦'.number_format((float) $w->fee_amount) : null],
                    'badges' => [static::status($w)],
                    'meta' => $w->created_at?->format('j M Y, H:i'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('Asked')->dateTime('j M Y, H:i')->sortable(),
                    Tables\Columns\TextColumn::make('amount_to_send')->label('To send')->money('NGN')->weight('bold')
                        ->description(fn (WithdrawalRequest $w) => (float) $w->fee_amount > 0 ? 'fee ₦'.number_format((float) $w->fee_amount) : null),
                    Tables\Columns\TextColumn::make('bankAccount.account_number')->label('To')->description(fn (WithdrawalRequest $w) => $w->bankAccount?->bank_name),
                    Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state, WithdrawalRequest $w) => static::status($w)[0])->color(fn (WithdrawalRequest $w) => static::status($w)[1]),
                    Tables\Columns\TextColumn::make('updated_at')->label('Last change')->since(),
                ]),
            ])
            ->emptyStateHeading('No withdrawals yet');
    }
}
