<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\TutorialResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Tutorial;
use App\Services\AdminAuditLogService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Site → Tutorials (item 45b): write, edit, schedule, feature and hide the
 * Learning Hub's guides (/support/tutorials). Replaces the WordPress
 * admin that used to be the only way to change them.
 */
class TutorialResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = Tutorial::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Site';

    protected static ?int $navigationSort = 3;

    public static function status(Tutorial $t): array
    {
        return match (true) {
            ! $t->is_published => ['Hidden', 'gray'],
            $t->published_at?->isFuture() => ['Goes live '.$t->published_at->format('j M, H:i'), 'info'],
            default => ['On the site', 'success'],
        };
    }

    public static function form(Form $form): Form
    {
        return $form->columns(['lg' => 3])->schema([
            Forms\Components\Section::make('Tutorial')->columnSpan(['lg' => 2])->schema([
                Forms\Components\TextInput::make('title')->required()->maxLength(150),
                Forms\Components\Textarea::make('excerpt')->label('Short summary')->rows(2)->maxLength(300)
                    ->helperText('Shown in the list. Leave empty to use the start of the tutorial.'),
                Forms\Components\RichEditor::make('content')
                    ->required()
                    ->disableToolbarButtons(['attachFiles', 'codeBlock'])
                    ->helperText('Anything unsafe (scripts, hidden code) is removed automatically when you save.'),
            ]),
            Forms\Components\Section::make('Settings')->columnSpan(['lg' => 1])->schema([
                Forms\Components\Toggle::make('is_published')->label('Show on the site')->default(true),
                Forms\Components\DateTimePicker::make('published_at')->label('Go live at')->seconds(false)
                    ->helperText('Leave empty to show it straight away, or pick a time to schedule it.'),
                Forms\Components\Toggle::make('is_featured')->label('Featured')
                    ->helperText('Shown big at the top of the Learning Hub. Only one can be featured, so this switches it off on the others.'),
                Forms\Components\TextInput::make('category')->datalist(Tutorial::CATEGORIES)->default('Guide')->required()->maxLength(40),
                Forms\Components\TextInput::make('read_time')->label('Reading time')->default('3 min')->maxLength(20),
                Forms\Components\TextInput::make('video_url')->label('Video link (optional)')->url()
                    ->rule('regex:#^https://(www\.|m\.)?(youtube\.com|youtu\.be|vimeo\.com)/#i')
                    ->validationMessages(['regex' => 'Use a YouTube or Vimeo link.'])
                    ->placeholder('https://youtube.com/watch?v=...'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                MobileCard::make(fn (Tutorial $t) => [
                    'title' => ($t->is_featured ? '★ ' : '').$t->title,
                    'lines' => [$t->category.' · '.$t->read_time.' · '.$t->helpful_count.' found it helpful'],
                    'badges' => [static::status($t)],
                    'meta' => 'Edited '.$t->updated_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\IconColumn::make('is_featured')->label('')->icon(fn ($state) => $state ? 'heroicon-s-star' : null)->color('warning'),
                    Tables\Columns\TextColumn::make('title')->searchable()->weight('bold')->limit(60)->description(fn (Tutorial $t) => $t->category.' · '.$t->read_time),
                    Tables\Columns\TextColumn::make('status')->badge()->state(fn (Tutorial $t) => static::status($t)[0])->color(fn (Tutorial $t) => static::status($t)[1]),
                    Tables\Columns\TextColumn::make('helpful_count')->label('Helpful')->sortable()->alignCenter(),
                    Tables\Columns\TextColumn::make('updated_at')->label('Edited')->since()->sortable(),
                ]),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')->label('On the site'),
                Tables\Filters\SelectFilter::make('category')->options(array_combine(Tutorial::CATEGORIES, Tutorial::CATEGORIES)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggle')
                    ->label(fn (Tutorial $t) => $t->is_published ? 'Hide' : 'Show')
                    ->icon(fn (Tutorial $t) => $t->is_published ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color('gray')
                    ->action(function (Tutorial $t) {
                        $t->update(['is_published' => ! $t->is_published]);
                        static::changed($t->is_published ? 'tutorial.shown' : 'tutorial.hidden', $t);
                    }),
                Tables\Actions\DeleteAction::make()->after(fn (Tutorial $t) => static::changed('tutorial.deleted', $t)),
            ])
            ->emptyStateHeading('No tutorials yet')
            ->emptyStateDescription('Write the first guide for your customers.');
    }

    public static function changed(string $action, Tutorial $tutorial): void
    {
        app(AdminAuditLogService::class)->record(static::admin(), $action, Tutorial::class, (int) $tutorial->id, ['title' => $tutorial->title]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTutorials::route('/'),
            'create' => Pages\CreateTutorial::route('/create'),
            'edit' => Pages\EditTutorial::route('/{record}/edit'),
        ];
    }
}
