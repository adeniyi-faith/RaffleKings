<?php

namespace App\Filament\Resources;

use App\Exceptions\AiUnavailableException;
use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\PredictionResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\WpUser;
use App\Models\Prediction;
use App\Services\AdminAuditLogService;
use App\Services\Ai\GeminiClient;
use App\Services\Engagement\Predictions;
use App\Services\Engagement\PredictionWriter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
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
            Forms\Components\Section::make('Written by AI')
                ->description('Only staff see this. Check the facts against the page the AI read before publishing.')
                ->visible(fn (?Prediction $record) => $record && ($record->is_draft || filled($record->ai_note) || filled($record->source_url)))
                ->schema([
                    Forms\Components\Placeholder::make('ai_note_view')->label('AI note')
                        ->content(fn (?Prediction $record) => $record?->ai_note ?: '—'),
                    Forms\Components\Placeholder::make('source_view')->label('Page it read')
                        ->content(fn (?Prediction $record) => static::sourceLink($record)),
                    Forms\Components\Placeholder::make('suggested_view')->label('Answer the AI suggests')
                        ->content(fn (?Prediction $record) => $record && $record->suggested_option !== null ? ($record->options[$record->suggested_option] ?? '—') : '—'),
                ]),
        ]);
    }

    private static function stage(Prediction $p): string
    {
        return match (true) {
            $p->is_draft => 'Draft: check and publish',
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
                    'badges' => [[static::stage($record), in_array(static::stage($record), ['Closed: settle it', 'Draft: check and publish'], true) ? 'warning' : 'gray']],
                    'meta' => 'Closes '.$record->closes_at->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('question')->wrap()->limit(80)
                        ->description(fn (Prediction $record) => implode(' · ', $record->options)),
                    Tables\Columns\TextColumn::make('answers_count')->counts('answers')->label('Answers'),
                    Tables\Columns\TextColumn::make('points'),
                    Tables\Columns\TextColumn::make('closes_at')->label('Closes')->dateTime('j M, H:i')->sortable(),
                    Tables\Columns\TextColumn::make('stage')->badge()->state(fn (Prediction $record) => static::stage($record))
                        ->color(fn (string $state) => in_array($state, ['Closed: settle it', 'Draft: check and publish'], true) ? 'warning' : ($state === 'Open' ? 'success' : 'gray')),
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (Prediction $record) => $record->is_draft)
                    ->requiresConfirmation()
                    ->modalHeading('Publish this question?')
                    ->modalDescription(fn (Prediction $record) => 'Customers can answer it from now until '.$record->closes_at->setTimezone(config('raffles.timezone'))->format('j M, H:i').'. Check the question, answers and closing time first.')
                    ->action(fn (Prediction $record) => static::attempt(function () use ($record) {
                        if ($record->closes_at->isPast()) {
                            throw new RuntimeException('Its closing time has passed. Edit the closing time first.');
                        }
                        $record->update(['is_draft' => false]);
                        app(AdminAuditLogService::class)->record(static::admin(), 'prediction.published', Prediction::class, $record->id);
                    }, 'Published. Customers can answer it now.')),
                Tables\Actions\Action::make('checkResult')
                    ->label('Check result with AI')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->visible(fn (Prediction $record) => ! $record->is_draft && ! $record->settled_at && $record->closes_at->isPast() && app(GeminiClient::class)->switchedOn())
                    ->action(function (Prediction $record) {
                        try {
                            $found = app(PredictionWriter::class)->checkResult($record);
                        } catch (AiUnavailableException $e) {
                            Notification::make()->title('AI could not check this')->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        $found['option'] === null
                            ? Notification::make()->title('No clear result found yet')->body($found['note'])->warning()->persistent()->send()
                            : Notification::make()->title('The AI suggests: '.$record->options[$found['option']])
                                ->body($found['note'].' Press "Settle" to check it and pay the points. Nothing has been paid yet.')->success()->persistent()->send();
                    }),
                Tables\Actions\Action::make('settle')
                    ->label('Settle')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Prediction $record) => ! $record->settled_at && $record->closes_at->isPast())
                    ->form(fn (Prediction $record) => [
                        Forms\Components\Radio::make('correct')->label('The right answer')->options($record->options)->required()
                            ->default($record->suggested_option)
                            ->helperText($record->suggested_option !== null
                                ? new HtmlString('Picked by the AI\'s result check. Make sure it is right. '.e((string) $record->ai_note).' '.static::sourceLink($record))
                                : null),
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
            ->bulkActions([
                Tables\Actions\BulkAction::make('publishDrafts')
                    ->label('Publish the drafts')
                    ->icon('heroicon-o-eye')
                    ->requiresConfirmation()
                    ->modalDescription('Only drafts whose closing time is still ahead are published.')
                    ->action(function (Collection $records) {
                        $ready = $records->filter(fn (Prediction $p) => $p->is_draft && $p->closes_at->isFuture());
                        $ready->each(fn (Prediction $p) => $p->update(['is_draft' => false]));
                        if ($ready->isNotEmpty() && ($admin = auth('wordpress')->user()) instanceof WpUser) {
                            app(AdminAuditLogService::class)->record($admin, 'prediction.published', Prediction::class, $ready->first()->id, ['count' => $ready->count()]);
                        }
                        Notification::make()->title($ready->count().' published')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No predictions yet')
            ->emptyStateDescription('Add a question for customers to answer today.');
    }

    /** The page the AI read, as a link that opens in a new tab. */
    private static function sourceLink(?Prediction $record): HtmlString|string
    {
        if (! $record || ! filled($record->source_url) || ! preg_match('#^https?://#i', $record->source_url)) {
            return '—';
        }

        return new HtmlString('<a href="'.e($record->source_url).'" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline">'.e(parse_url($record->source_url, PHP_URL_HOST) ?: 'Open the page').'</a>');
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
