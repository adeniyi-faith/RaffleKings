<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\MoneyReviewItemResource\Pages;
use App\Models\Legacy\WpUser;
use App\Models\MoneyReviewItem;
use App\Services\AdminAuditLogService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Needs checking": everything the nightly money checks found (our books vs
 * wallets, our records vs Paystack and Flutterwave). Each one has a date, an
 * owner and, once sorted out, a written result (money-safety audit G1, G3).
 */
class MoneyReviewItemResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    protected static ?string $model = MoneyReviewItem::class;

    protected static ?string $slug = 'needs-checking';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Needs checking';

    protected static ?string $modelLabel = 'item to check';

    protected static ?int $navigationSort = 4;

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
        $count = MoneyReviewItem::query()->where('status', 'open')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('found_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('What was found')->wrap()->weight('bold')
                    ->description(fn (MoneyReviewItem $record) => $record->details),
                Tables\Columns\TextColumn::make('found_at')->label('Found')->since()->sortable(),
                Tables\Columns\TextColumn::make('owner_id')->label('Who has it')
                    ->formatStateUsing(fn ($state) => $state ? (WpUser::find($state)?->display_name ?? "#{$state}") : 'Nobody yet'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'open' ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => $state === 'open' ? 'Open' : 'Sorted out')
                    ->description(fn (MoneyReviewItem $record) => $record->resolution_note),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['open' => 'Open', 'resolved' => 'Sorted out'])->default('open'),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('take')
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('I\'ll look at it')
                    ->icon('heroicon-o-hand-raised')
                    ->color('gray')
                    ->visible(fn (MoneyReviewItem $record) => $record->status === 'open')
                    ->action(function (MoneyReviewItem $record) {
                        $record->update(['owner_id' => static::admin()->ID]);

                        app(AdminAuditLogService::class)->record(static::admin(), 'money_check.taken', MoneyReviewItem::class, $record->id, ['title' => $record->title]);
                    }),
                Tables\Actions\Action::make('resolve')
                    ->hidden(fn () => ! static::staffCan('money.pay'))
                    ->label('Sorted out')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (MoneyReviewItem $record) => $record->status === 'open')
                    ->form([
                        Forms\Components\Textarea::make('note')->label('What was it, and what did you do?')->required()->maxLength(1000),
                    ])
                    ->action(function (MoneyReviewItem $record, array $data) {
                        $record->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => static::admin()->ID, 'resolution_note' => $data['note']]);

                        app(AdminAuditLogService::class)->record(static::admin(), 'money_check.resolved', MoneyReviewItem::class, $record->id, ['title' => $record->title, 'note' => $data['note']]);
                    }),
            ])
            ->emptyStateHeading('Nothing needs checking')
            ->emptyStateDescription('The nightly checks of the books and of Paystack and Flutterwave found nothing wrong.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMoneyReviewItems::route('/')];
    }
}
