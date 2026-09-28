<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserPointsResource\Pages;
use App\Models\UserPoints;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * OVERHAUL_CHECKLIST.md item 45 — reward points overview: who holds how
 * many points, their daily streak and last claim. Read-only (points move
 * through the Rewards page; an admin corrects a balance from Users →
 * Adjust balance). 10 points = ₦1 when redeemed.
 */
class UserPointsResource extends Resource
{
    protected static ?string $model = UserPoints::class;

    protected static ?string $slug = 'points';

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Reward points';

    protected static ?string $modelLabel = 'points balance';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('balance', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.display_name')
                    ->label('Customer')
                    ->description(fn (UserPoints $r) => $r->user?->user_email)
                    ->searchable(['display_name', 'user_login', 'user_email']),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Points')
                    ->numeric()
                    ->sortable()
                    ->description(fn (UserPoints $r) => 'worth ₦'.number_format($r->balance / 10))
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total owed')),
                Tables\Columns\TextColumn::make('streak_count')->label('Streak (days)')->sortable(),
                Tables\Columns\TextColumn::make('last_claim_date')->label('Last daily claim')->date()->placeholder('Never')->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('redeemable')
                    ->label('Can redeem now (100+ points)')
                    ->query(fn (Builder $q) => $q->where('balance', '>=', 100)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserPoints::route('/'),
        ];
    }
}
