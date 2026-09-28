<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\LiveChatResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\LiveDrawComment;
use App\Services\ChatModerationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * OVERHAUL_CHECKLIST.md item 45 — moderating the public live-draw chat
 * (the old admin's "Live Comments" page, for the new chat). Hide a
 * message (it vanishes from every viewer's screen at once), mute its
 * author for a while, or bring a message back. Messages are already
 * filtered for links, phone numbers and blocked words before they're
 * posted (ChatModerationService::clean()).
 */
class LiveChatResource extends Resource
{
    use RunsAdminActions;

    protected static ?string $model = LiveDrawComment::class;

    protected static ?string $slug = 'live-chat';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-oval-left-ellipsis';

    protected static ?string $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Live-draw chat';

    protected static ?string $modelLabel = 'chat message';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'raffle']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /** @var array<int, bool> muted state per author, looked up once per page load */
    private static array $muted = [];

    private static function moderation(): ChatModerationService
    {
        return app(ChatModerationService::class);
    }

    private static function isMuted(int $userId): bool
    {
        return static::$muted[$userId] ??= static::moderation()->isMuted($userId);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('15s')
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (LiveDrawComment $record) => [
                    'title' => $record->user?->display_name ?: $record->user?->user_login ?: "User #{$record->user_id}",
                    'body' => $record->body,
                    'lines' => [$record->raffle?->title],
                    'badges' => [
                        $record->hidden_at ? ['Hidden', 'gray'] : ['Showing', 'success'],
                        static::isMuted($record->user_id) ? ['Author muted', 'danger'] : null,
                    ],
                    'meta' => $record->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('body')
                        ->label('Message')
                        ->wrap()
                        ->description(fn (LiveDrawComment $record) => ($record->user?->display_name ?: $record->user?->user_login ?: "User #{$record->user_id}")
                            .(static::isMuted($record->user_id) ? ' · muted' : '')),
                    Tables\Columns\TextColumn::make('raffle.title')->label('Live draw')->limit(30),
                    Tables\Columns\TextColumn::make('created_at')->label('Posted')->since()->sortable(),
                    Tables\Columns\TextColumn::make('visibility')
                        ->label('Status')
                        ->badge()
                        ->state(fn (LiveDrawComment $record) => $record->hidden_at ? 'Hidden' : 'Showing')
                        ->color(fn (string $state) => $state === 'Hidden' ? 'gray' : 'success'),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('visibility')
                    ->options(['showing' => 'Showing', 'hidden' => 'Hidden'])
                    ->default('showing')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'showing' => $query->whereNull('hidden_at'),
                        'hidden' => $query->whereNotNull('hidden_at'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('raffle_id')->label('Live draw')->relationship('raffle', 'title'),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->actionsColumnLabel('Action')
            ->actions([
                Tables\Actions\Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (LiveDrawComment $record) => $record->hidden_at === null)
                    ->action(fn (LiveDrawComment $record) => static::attempt(
                        fn () => static::moderation()->hide(static::admin(), $record),
                        'Message hidden for everyone.',
                    )),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('unhide')
                        ->label('Show again')
                        ->icon('heroicon-o-eye')
                        ->visible(fn (LiveDrawComment $record) => $record->hidden_at !== null)
                        ->action(fn (LiveDrawComment $record) => static::attempt(
                            fn () => static::moderation()->unhide(static::admin(), $record),
                            'Message restored.',
                        )),
                    Tables\Actions\Action::make('mute')
                        ->label('Mute author')
                        ->icon('heroicon-o-speaker-x-mark')
                        ->color('warning')
                        ->visible(fn (LiveDrawComment $record) => ! static::isMuted($record->user_id))
                        ->form([
                            Forms\Components\Select::make('hours')
                                ->label('For how long')
                                ->options(['1' => '1 hour', '24' => '24 hours', '168' => '7 days', 'forever' => 'Until unmuted'])
                                ->default('24')
                                ->required(),
                        ])
                        ->action(fn (LiveDrawComment $record, array $data) => static::attempt(
                            function () use ($record, $data) {
                                static::moderation()->mute(static::admin(), $record->user_id, $data['hours'] === 'forever' ? null : (int) $data['hours']);
                                static::$muted = [];
                            },
                            'Author muted. They can still watch, but can\'t post.',
                        )),
                    Tables\Actions\Action::make('unmute')
                        ->label('Unmute author')
                        ->icon('heroicon-o-speaker-wave')
                        ->visible(fn (LiveDrawComment $record) => static::isMuted($record->user_id))
                        ->action(fn (LiveDrawComment $record) => static::attempt(
                            function () use ($record) {
                                static::moderation()->unmute(static::admin(), $record->user_id);
                                static::$muted = [];
                            },
                            'Author can post again.',
                        )),
                ])->label('More')->icon('heroicon-m-ellipsis-vertical')->button()->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('hideSelected')
                    ->label('Hide selected')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => static::attempt(function () use ($records) {
                        $admin = static::admin();
                        $records->each(fn (LiveDrawComment $c) => static::moderation()->hide($admin, $c));
                    }, $records->count().' message(s) hidden.'))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('No chat messages')
            ->emptyStateDescription('Messages from live-draw chats appear here as they\'re posted.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLiveChat::route('/'),
        ];
    }
}
