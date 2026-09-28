<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\ReferralCommissionResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\ReferralCommission;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * OVERHAUL_CHECKLIST.md item 45 — referral commissions paid (the old
 * admin's "Referral System" page). One row per referred customer's first
 * deposit; commissions are paid automatically (ReferralCommissionService),
 * so this screen is read-only: who referred whom, and what it earned.
 */
class ReferralCommissionResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = ReferralCommission::class;

    protected static ?string $slug = 'referrals';

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'Users';

    protected static ?string $navigationLabel = 'Referrals';

    protected static ?string $modelLabel = 'referral commission';

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
        return parent::getEloquentQuery()->with(['referrer', 'referee']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (ReferralCommission $r) => [
                    'title' => $r->referrer?->display_name ?: $r->referrer?->user_login,
                    'amount' => static::naira($r->commission_amount),
                    'lines' => [
                        'Brought '.($r->referee?->display_name ?: $r->referee?->user_login),
                        'First top-up '.static::naira($r->deposit_amount).' · '.rtrim(rtrim(number_format((float) $r->commission_rate * 100, 2), '0'), '.').'% commission',
                    ],
                    'meta' => $r->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('Paid')->since()->sortable(),
                    Tables\Columns\TextColumn::make('referrer.display_name')
                        ->label('Referrer (earned)')
                        ->description(fn (ReferralCommission $r) => $r->referrer?->user_email)
                        ->searchable(['display_name', 'user_login', 'user_email']),
                    Tables\Columns\TextColumn::make('referee.display_name')
                        ->label('Friend they brought')
                        ->description(fn (ReferralCommission $r) => $r->referee?->user_email),
                    Tables\Columns\TextColumn::make('deposit_amount')->label('Friend\'s first top-up')->formatStateUsing(fn ($state) => static::naira($state)),
                    Tables\Columns\TextColumn::make('commission_amount')
                        ->label('Commission')
                        ->formatStateUsing(fn ($state) => static::naira($state))
                        ->weight('bold')
                        ->description(fn (ReferralCommission $r) => rtrim(rtrim(number_format((float) $r->commission_rate * 100, 2), '0'), '.').'%')
                        ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->formatStateUsing(fn ($state) => static::naira((float) $state))),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('referrer_user_id')
                    ->label('Referrer')
                    ->relationship('referrer', 'display_name')
                    ->searchable(),
            ])
            ->emptyStateHeading('No referral commissions yet')
            ->emptyStateDescription('A commission appears here when a referred friend makes their first top-up.');
    }

    public static function getWidgets(): array
    {
        return [ReferralCommissionResource\Widgets\ReferralStats::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralCommissions::route('/'),
        ];
    }
}
