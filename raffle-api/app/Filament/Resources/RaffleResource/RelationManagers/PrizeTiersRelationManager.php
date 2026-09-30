<?php

namespace App\Filament\Resources\RaffleResource\RelationManagers;

use App\Services\OddsCalculator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PrizeTiersRelationManager extends RelationManager
{
    protected static string $relationship = 'prizeTiers';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('tier_name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('prize_description')
                    ->maxLength(255),
                Forms\Components\TextInput::make('cash_value')
                    ->label('Cash value (₦)')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Forms\Components\TextInput::make('winner_count')
                    ->label('Number of winners at this tier')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->helperText('The draw engine awards this tier to this many separate people, not one.'),
                Forms\Components\TextInput::make('rank')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('1 = grand prize, ordered ascending.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tier_name')
            ->defaultSort('rank')
            ->columns([
                Tables\Columns\TextColumn::make('rank'),
                Tables\Columns\TextColumn::make('tier_name'),
                Tables\Columns\TextColumn::make('prize_description'),
                Tables\Columns\TextColumn::make('cash_value')->naira(),
                Tables\Columns\TextColumn::make('winner_count'),
                // What a player sees on the raffle page, worked out from these same prize levels.
                Tables\Columns\TextColumn::make('chance_per_ticket')
                    ->label('Chance per ticket')
                    ->state(fn ($record) => ($one = OddsCalculator::oneIn((int) $this->getOwnerRecord()->max_tickets, (int) $record->winner_count)) ? '1 in '.number_format($one) : '–'),
                Tables\Columns\TextColumn::make('chance_with_five')
                    ->label('Chance with 5 tickets')
                    ->state(fn ($record) => OddsCalculator::percent(OddsCalculator::atLeastOne((int) $this->getOwnerRecord()->max_tickets, (int) $record->winner_count, 5))),
            ])
            // Once the draw has run, the prize tiers are part of its public
            // proof (the verify page recomputes the winners from them), so
            // they're locked — changing them afterwards would make an
            // honest draw look tampered with (item 45).
            ->description(function () {
                $raffle = $this->getOwnerRecord();

                if ($raffle->isDrawn()) {
                    return 'This raffle has been drawn, so its prize tiers are locked.';
                }

                $raffle->loadMissing('prizeTiers');
                $sum = app(OddsCalculator::class)->setupSummary($raffle);
                $naira = fn ($n) => '₦'.number_format((float) $n);

                if ($sum['sales'] <= 0) {
                    return null;
                }

                return "If all ".number_format($sum['pool'])." tickets sell: {$naira($sum['sales'])} in, {$naira($sum['prize_total'])} in prizes ({$sum['prize_share']}% of sales), gaming tax about {$naira($sum['tax'])}, {$naira($sum['left'])} left. "
                    .($sum['one_in_any'] ? "Any one ticket has about a 1 in {$sum['one_in_any']} chance of winning something. " : '')
                    .implode(' ', $sum['warnings']);
            })
            ->headerActions([
                Tables\Actions\CreateAction::make()->visible(fn () => ! $this->getOwnerRecord()->isDrawn()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => ! $this->getOwnerRecord()->isDrawn()),
                Tables\Actions\DeleteAction::make()->visible(fn () => ! $this->getOwnerRecord()->isDrawn()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ])->visible(fn () => ! $this->getOwnerRecord()->isDrawn()),
            ]);
    }
}
