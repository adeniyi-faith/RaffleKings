<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\ComplianceCaseResource\Pages;
use App\Models\ComplianceCase;
use App\Services\Compliance\ComplianceCases;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Things that need looking into: a case per concern, notes that can't be changed, closed by a second person. */
class ComplianceCaseResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    protected static ?string $model = ComplianceCase::class;

    protected static ?string $slug = 'cases';

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Cases';

    protected static ?string $modelLabel = 'case';

    protected static ?int $navigationSort = 6;

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
        $count = ComplianceCase::query()->where('status', '!=', 'closed')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->weight('bold')->wrap()->description(fn (ComplianceCase $r) => $r->details),
                Tables\Columns\TextColumn::make('user.display_name')->label('Customer')->description(fn (ComplianceCase $r) => "#{$r->user_id}"),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'open' => 'danger', 'investigating' => 'warning', default => 'success'
                    })
                    ->formatStateUsing(fn ($state) => ['open' => 'Open', 'investigating' => 'Being looked into', 'closed' => 'Closed'][$state] ?? $state)
                    ->description(fn (ComplianceCase $r) => $r->outcome),
                Tables\Columns\TextColumn::make('notes')->label('Notes')->html()->state(fn (ComplianceCase $r) => new HtmlString($r->notes->map(fn ($n) => '<div><small>'.e($n->created_at?->format('j M H:i')).'</small> '.e($n->body).'</div>')->implode(''))),
                Tables\Columns\TextColumn::make('created_at')->label('Opened')->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['open' => 'Open', 'investigating' => 'Being looked into', 'closed' => 'Closed'])->default('open'),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('take')->label('I\'ll look into it')->icon('heroicon-o-hand-raised')->color('gray')
                    ->visible(fn (ComplianceCase $r) => $r->status !== 'closed' && static::staffCan('customers.manage'))
                    ->action(fn (ComplianceCase $r) => static::attempt(fn () => app(ComplianceCases::class)->take(static::admin(), $r), 'It\'s yours.')),
                Tables\Actions\Action::make('note')->label('Add a note')->icon('heroicon-o-pencil-square')->color('gray')
                    ->visible(fn (ComplianceCase $r) => $r->status !== 'closed' && static::staffCan('customers.manage'))
                    ->modalDescription('Notes can\'t be changed or removed afterwards.')
                    ->form([Forms\Components\Textarea::make('body')->label('Note')->required()->maxLength(4000)])
                    ->action(fn (ComplianceCase $r, array $data) => static::attempt(fn () => app(ComplianceCases::class)->note(static::admin(), $r, $data['body']), 'Note added.')),
                Tables\Actions\Action::make('close')->label('Close')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (ComplianceCase $r) => $r->status !== 'closed' && static::staffCan('customers.manage') && (int) $r->opened_by !== (int) static::admin()->ID)
                    ->modalDescription('A different staff member from the one who opened the case has to close it.')
                    ->form([Forms\Components\Textarea::make('outcome')->label('What was found and decided?')->required()->maxLength(4000)])
                    ->action(fn (ComplianceCase $r, array $data) => static::attempt(fn () => app(ComplianceCases::class)->close(static::admin(), $r, $data['outcome']), 'Case closed.')),
            ])
            ->emptyStateHeading('No cases')
            ->emptyStateDescription('Open one from a customer\'s profile with "Open a case".');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListComplianceCases::route('/')];
    }
}
