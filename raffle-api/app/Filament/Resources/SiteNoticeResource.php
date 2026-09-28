<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\SiteNoticeResource\Pages;
use App\Filament\Support\MobileCard;
use App\Http\Controllers\Api\SiteNoticeController;
use App\Models\Legacy\RaffleSiteNotice;
use App\Services\AdminAuditLogService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

/**
 * OVERHAUL_CHECKLIST.md item 45 — site announcements (the old admin's
 * "Site Alerts" page). Every change is audit-logged and clears the
 * public cache so it shows on the site straight away.
 */
class SiteNoticeResource extends Resource
{
    use RunsAdminActions;

    protected static ?string $model = RaffleSiteNotice::class;

    protected static ?string $slug = 'announcements';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?string $modelLabel = 'announcement';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('What customers see')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')->maxLength(100)->placeholder('Optional heading'),
                    Forms\Components\Select::make('type')
                        ->label('Style')
                        ->options(RaffleSiteNotice::TYPES)
                        ->default('info')
                        ->required(),
                    Forms\Components\Textarea::make('message')->required()->rows(3)->maxLength(500)->columnSpanFull(),
                    Forms\Components\TextInput::make('link_label')->label('Button text')->maxLength(40)->placeholder('e.g. See raffles'),
                    Forms\Components\TextInput::make('link_url')
                        ->label('Button link')
                        ->maxLength(255)
                        ->placeholder('/raffles or https://…')
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && ! RaffleSiteNotice::isSafeLink($value)) {
                                $fail('Use a page on this site (starting with /) or a full https:// address.');
                            }
                        }),
                ]),
            Forms\Components\Section::make('Where, when and how often')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('location')
                        ->label('Placement')
                        ->options(RaffleSiteNotice::LOCATIONS)
                        ->default('toast_top')
                        ->required(),
                    Forms\Components\Select::make('frequency')
                        ->label('Show it')
                        ->options(RaffleSiteNotice::FREQUENCIES)
                        ->default('once_session')
                        ->required(),
                    Forms\Components\TextInput::make('dismiss_sec')
                        ->label('Hide automatically after (seconds)')
                        ->helperText('0 = stays until the customer closes it.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(120)
                        ->default(0),
                    Forms\Components\Toggle::make('is_active')->label('Switched on')->default(true)->inline(false),
                    Forms\Components\DateTimePicker::make('starts_at')->label('Start showing')->helperText('Optional. Leave empty to start now.')->seconds(false),
                    Forms\Components\DateTimePicker::make('ends_at')->label('Stop showing')->helperText('Optional. It switches itself off.')->seconds(false)->after('starts_at'),
                ]),
        ]);
    }

    private static function showingState(RaffleSiteNotice $record): string
    {
        return match (true) {
            $record->isLive() => 'Showing',
            ! $record->is_active => 'Switched off',
            $record->starts_at?->isFuture() => 'Starts '.$record->starts_at->diffForHumans(),
            default => 'Ended',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (RaffleSiteNotice $record) => [
                    'title' => $record->title ?: 'Announcement',
                    'body' => $record->message,
                    'lines' => [(RaffleSiteNotice::LOCATIONS[$record->location] ?? $record->location).' · '.(RaffleSiteNotice::FREQUENCIES[$record->frequency] ?? $record->frequency)],
                    'badges' => [[static::showingState($record), static::showingState($record) === 'Showing' ? 'success' : 'gray']],
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('message')
                        ->label('Announcement')
                        ->limit(70)
                        ->wrap()
                        ->description(fn (RaffleSiteNotice $record) => $record->title),
                    Tables\Columns\TextColumn::make('location')
                        ->label('Placement')
                        ->formatStateUsing(fn (string $state) => RaffleSiteNotice::LOCATIONS[$state] ?? $state)
                        ->description(fn (RaffleSiteNotice $record) => RaffleSiteNotice::FREQUENCIES[$record->frequency] ?? $record->frequency),
                    Tables\Columns\TextColumn::make('showing')
                        ->label('On the site now')
                        ->badge()
                        ->state(fn (RaffleSiteNotice $record) => static::showingState($record))
                        ->color(fn (string $state) => $state === 'Showing' ? 'success' : 'gray'),
                    Tables\Columns\ToggleColumn::make('is_active')
                        ->label('On')
                        ->afterStateUpdated(fn (RaffleSiteNotice $record, bool $state) => static::changed($state ? 'site_notice.switched_on' : 'site_notice.switched_off', $record)),
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->after(fn (RaffleSiteNotice $record) => static::changed('site_notice.deleted', $record)),
            ])
            ->emptyStateHeading('No announcements')
            ->emptyStateDescription('Create one to show a message across the whole site.');
    }

    /** Audit-log a change and make it show on the site straight away. */
    public static function changed(string $action, RaffleSiteNotice $notice): void
    {
        Cache::forget(SiteNoticeController::CACHE_KEY);

        app(AdminAuditLogService::class)->record(static::admin(), $action, RaffleSiteNotice::class, (int) $notice->id, [
            'title' => $notice->title,
            'message' => mb_strimwidth((string) $notice->message, 0, 120, '…'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteNotices::route('/'),
            'create' => Pages\CreateSiteNotice::route('/create'),
            'edit' => Pages\EditSiteNotice::route('/{record}/edit'),
        ];
    }
}
