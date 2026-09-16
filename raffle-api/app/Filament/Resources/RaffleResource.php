<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RaffleResource\Pages;
use App\Filament\Resources\RaffleResource\RelationManagers\PrizeTiersRelationManager;
use App\Models\Raffle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RaffleResource extends Resource
{
    protected static ?string $model = Raffle::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Raffles';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('excerpt')
                    ->maxLength(2000)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('price')
                    ->label('Ticket price (₦)')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                Forms\Components\TextInput::make('max_tickets')
                    ->required()
                    ->numeric()
                    ->minValue(1),
                Forms\Components\TextInput::make('grand_prize')
                    ->maxLength(255),
                Forms\Components\DatePicker::make('expiry'),
                Forms\Components\Select::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed'])
                    ->default('draft')
                    ->required()
                    ->helperText('Admin intent only — a raffle is separately treated as sold out once every ticket number has actually been bought (never inferred from this alone).'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('price')->money('NGN'),
                Tables\Columns\TextColumn::make('max_tickets')->label('Max tickets'),
                Tables\Columns\TextColumn::make('sold')
                    ->label('Sold')
                    ->state(fn (Raffle $record): int => $record->soldTickets()),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'published',
                        'danger' => 'closed',
                    ]),
                Tables\Columns\TextColumn::make('expiry')->date(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published', 'closed' => 'Closed']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PrizeTiersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRaffles::route('/'),
            'create' => Pages\CreateRaffle::route('/create'),
            'edit' => Pages\EditRaffle::route('/{record}/edit'),
        ];
    }
}
