<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\PredictionResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Prediction;
use App\Services\AdminAuditLogService;
use App\Services\Engagement\Predictions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use InvalidArgumentException;
use RuntimeException;

/**
 * Daily predictions (Phase 11): write the questions customers answer for
 * free, then "Settle" each one with the right answer to pay the points.
 */
class PredictionResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = Prediction::class;

    protected static ?string $slug = 'predictions';

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Daily predictions';

    protected static ?string $modelLabel = 'prediction';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The question')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('category')->options(Prediction::CATEGORIES)->default('quiz')->required(),
                    Forms\Components\TextInput::make('points')->label('Points for a right answer')->numeric()->minValue(0)->maxValue(100000)
                        ->default(fn () => (int) config('engagement.predictions.default_points', 50))->required(),
                    Forms\Components\TextInput::make('question')->required()->maxLength(255)->columnSpanFull()
                        ->placeholder('e.g. Who wins Saturday\'s Arsenal v Chelsea?'),
                    Forms\Components\TagsInput::make('options')->label('Answers (2 to 4)')->required()->columnSpanFull()
                        ->placeholder('Type an answer, then press Enter')
                        ->rules(['array', 'min:2', 'max:4'])
                        ->disabled(fn (?Prediction $record) => $record && $record->answers()->exists())
                        ->helperText(fn (?Prediction $record) => $record && $record->answers()->exists() ? 'Locked: customers have already answered.' : 'Keep the order: customers see them in this order.'),
                    Forms\Components\DateTimePicker::make('opens_at')->label('Opens')->seconds(false)->helperText('Empty = open as soon as you save.'),
                    Forms\Components\DateTimePicker::make('closes_at')->label('Closes (no answers after this)')->seconds(false)->required()->after('opens_at')
                        ->helperText('For a match, close it at kick-off.'),
                ]),
        ]);
    }

    private static function stage(Prediction $p): string
    {
        return match (true) {
            (bool) $p->settled_at => 'Settled',
            $p->isOpen() => 'Open',
            $p->closes_at->isPast() => 'Closed: settle it',
            default => 'Scheduled',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('closes_at', 'desc')
            ->columns([
                MobileCard::make(fn (Prediction $record) => [
                    'title' => $record->question,
                    'lines' => [implode(' · ', $record->options), $record->answers()->count().' answers · '.$record->points.' points'],
                    'badges' => [[static::stage($record), static::stage($record) === 'Closed: settle it' ? 'warning' : 'gray']],
                    'meta' => 'Closes '.$record->closes_at->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('question')->wrap()->limit(80)
                        ->description(fn (Prediction $record) => implode(' · ', $record->options)),
                    Tables\Columns\TextColumn::make('answers_count')->counts('answers')->label('Answers'),
                    Tables\Columns\TextColumn::make('points'),
                    Tables\Columns\TextColumn::make('closes_at')->label('Closes')->dateTime('j M, H:i')->sortable(),
                    Tables\Columns\TextColumn::make('stage')->badge()->state(fn (Prediction $record) => static::stage($record))
                        ->color(fn (string $state) => $state === 'Closed: settle it' ? 'warning' : ($state === 'Open' ? 'success' : 'gray')),
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('settle')
                    ->label('Settle')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Prediction $record) => ! $record->settled_at && $record->closes_at->isPast())
                    ->form(fn (Prediction $record) => [
                        Forms\Components\Radio::make('correct')->label('The right answer')->options($record->options)->required(),
                    ])
                    ->modalDescription('Everyone who picked it gets the points straight away. This can\'t be undone.')
                    ->action(fn (Prediction $record, array $data) => static::attempt(function () use ($record, $data) {
                        try {
                            $count = app(Predictions::class)->settle($record, (int) $data['correct']);
                        } catch (InvalidArgumentException $e) {
                            throw new RuntimeException($e->getMessage());
                        }

                        app(AdminAuditLogService::class)->record(static::admin(), 'prediction.settled', Prediction::class, $record->id, ['correct' => $record->options[(int) $data['correct']] ?? null, 'right' => $count]);
                    }, 'Settled. Points paid to everyone who got it right.')),
                Tables\Actions\EditAction::make()->visible(fn (Prediction $record) => ! $record->settled_at),
                Tables\Actions\DeleteAction::make()->visible(fn (Prediction $record) => ! $record->answers()->exists()),
            ])
            ->emptyStateHeading('No predictions yet')
            ->emptyStateDescription('Add a question for customers to answer today.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPredictions::route('/'),
            'create' => Pages\CreatePrediction::route('/create'),
            'edit' => Pages\EditPrediction::route('/{record}/edit'),
        ];
    }
}
