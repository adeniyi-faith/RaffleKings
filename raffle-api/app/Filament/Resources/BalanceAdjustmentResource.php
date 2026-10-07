<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\BalanceAdjustmentResource\Pages;
use App\Models\BalanceAdjustment;
use App\Services\BalanceAdjustments;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;

/**
 * Balance changes staff have asked for. Big ones wait here until a different
 * staff member approves them (money-safety audit I2).
 */
class BalanceAdjustmentResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    protected static ?string $model = BalanceAdjustment::class;

    protected static ?string $slug = 'balance-changes';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Balance changes';

    protected static ?string $modelLabel = 'balance change';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = BalanceAdjustment::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.display_name')->label('Customer')->description(fn (BalanceAdjustment $r) => "#{$r->user_id}"),
                Tables\Columns\TextColumn::make('change')->label('Change')->state(function (BalanceAdjustment $r) {
                    $sign = $r->direction === 'add' ? '+' : '-';
                    $label = ['wallet' => 'spending wallet', 'earnings' => 'winnings', 'points' => 'points'][$r->balance_type] ?? $r->balance_type;

                    return $r->balance_type === 'points' ? "{$sign}".number_format((float) $r->amount).' points' : "{$sign}₦".number_format((float) $r->amount, 2)." {$label}";
                })->weight('bold'),
                Tables\Columns\TextColumn::make('reason')->wrap()->limit(160),
                Tables\Columns\TextColumn::make('proposer.display_name')->label('Asked by'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning', 'applied' => 'success', default => 'gray'
                    })
                    ->formatStateUsing(fn ($state) => ['pending' => 'Waiting for approval', 'applied' => 'Done', 'rejected' => 'Turned down'][$state] ?? $state)
                    ->description(fn (BalanceAdjustment $r) => $r->decision_note),
                Tables\Columns\TextColumn::make('created_at')->label('Asked')->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Waiting for approval', 'applied' => 'Done', 'rejected' => 'Turned down'])->default('pending'),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (BalanceAdjustment $r) => $r->status === 'pending' && static::staffCan('money.pay') && (int) $r->proposed_by !== (int) static::admin()->ID)
                    ->requiresConfirmation()
                    ->modalDescription('This changes the customer\'s balance straight away.')
                    ->action(fn (BalanceAdjustment $r) => static::attempt(fn () => app(BalanceAdjustments::class)->approve(static::admin(), $r), 'Approved and applied.')),
                Tables\Actions\Action::make('reject')
                    ->label('Turn down')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (BalanceAdjustment $r) => $r->status === 'pending' && static::staffCan('money.pay'))
                    ->form([Forms\Components\Textarea::make('note')->label('Why?')->required()->maxLength(1000)])
                    ->action(fn (BalanceAdjustment $r, array $data) => static::attempt(fn () => app(BalanceAdjustments::class)->reject(static::admin(), $r, $data['note']), 'Turned down.')),
            ])
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Big balance changes show up here for a second staff member to approve.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBalanceAdjustments::route('/')];
    }
}
