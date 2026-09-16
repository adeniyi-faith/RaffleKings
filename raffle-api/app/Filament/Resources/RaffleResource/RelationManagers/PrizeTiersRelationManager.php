<?php

namespace App\Filament\Resources\RaffleResource\RelationManagers;

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
                Tables\Columns\TextColumn::make('cash_value')->money('NGN'),
                Tables\Columns\TextColumn::make('winner_count'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
