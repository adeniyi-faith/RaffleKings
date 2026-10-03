<?php

namespace App\Filament\Resources\DailyDropResource\RelationManagers;

use App\Models\DailyDropRun;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Every day the drop ran: what it paid and to which tickets. */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Drops so far';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('run_date')->label('Day')->date('j M Y'),
                Tables\Columns\TextColumn::make('result')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'paid' => 'Paid', 'no_sales' => 'No new sales', 'no_tickets' => 'No tickets yet', default => $state
                    })
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('sales_counted')->label('New sales')->formatStateUsing(fn ($state) => '₦'.number_format((float) $state)),
                Tables\Columns\TextColumn::make('pot')->label('Paid out')->formatStateUsing(fn ($state) => '₦'.number_format((float) $state)),
                Tables\Columns\TextColumn::make('tickets')->label('Winning tickets')
                    ->state(fn (DailyDropRun $r) => collect((array) $r->winners)->map(fn ($w) => '#'.$w['ticket_number'].' (₦'.number_format($w['amount']).')')->implode(', ') ?: '—'),
            ]);
    }
}
