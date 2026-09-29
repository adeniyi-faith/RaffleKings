<?php

namespace App\Filament\Resources\Legacy\WpUserResource\RelationManagers;

use App\Filament\Resources\PaymentResource;
use App\Filament\Support\MobileCard;
use App\Models\Deposit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Card / online top-ups (Paystack, Flutterwave), including failed and abandoned ones. */
class TopUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'deposits';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Top-ups';

    protected static ?string $icon = 'heroicon-o-credit-card';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Deposit $d) => PaymentResource::getUrl('view', ['record' => $d]))
            ->columns([
                MobileCard::make(fn (Deposit $d) => [
                    'title' => ucfirst((string) $d->gateway).' top-up',
                    'amount' => '₦'.number_format((float) $d->amount),
                    'lines' => [$d->failure_reason],
                    'badges' => [[PaymentResource::statusLabel($d), PaymentResource::statusColor($d)]],
                    'meta' => $d->created_at?->format('j M Y, H:i'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('Started')->dateTime('j M Y, H:i')->sortable(),
                    Tables\Columns\TextColumn::make('amount')->naira()->weight('bold'),
                    Tables\Columns\TextColumn::make('gateway')->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                    Tables\Columns\TextColumn::make('status')->badge()->state(fn (Deposit $d) => PaymentResource::statusLabel($d))->color(fn (Deposit $d) => PaymentResource::statusColor($d)),
                    Tables\Columns\TextColumn::make('reference')->fontFamily('mono')->size('xs')->copyable(),
                ]),
            ])
            ->emptyStateHeading('No card or online top-ups');
    }
}
