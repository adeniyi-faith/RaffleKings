<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\SupportTicketResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Services\AdminAuditLogService;
use App\Services\SupportTicketService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * OVERHAUL_CHECKLIST.md item 44 — the support inbox. Customers' tickets
 * (from the site's Help & Support page) could only be answered through
 * the JSON API before this. Staff read the whole conversation and reply
 * from one page; the customer is emailed the reply (SupportTicketService).
 * Replies and status changes are audit-logged, same as the API.
 */
class SupportTicketResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    /** Plain labels for the ticket statuses (see SupportTicketService::reply()). */
    public const STATUS_LABELS = [
        'open' => 'Waiting for us',
        'pending' => 'Waiting for customer',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    protected static ?string $model = SupportTicket::class;

    protected static ?string $slug = 'support-tickets';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Support tickets';

    protected static ?string $modelLabel = 'support ticket';

    // Found by subject from the admin's top search box.
    protected static ?string $recordTitleAttribute = 'subject';

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        return ['Ticket' => '#'.$record->id.' · '.$record->status];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = SupportTicket::query()->where('status', 'open')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user')->withCount('messages');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'open' => 'danger',
            'pending' => 'warning',
            'resolved' => 'success',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'asc')
            ->recordUrl(fn (SupportTicket $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                // Phone: each row is one card (App\Filament\Support\MobileCard).
                MobileCard::make(fn (SupportTicket $record) => [
                    'title' => $record->subject,
                    'lines' => [($record->user?->display_name ?: $record->user?->user_login)." · #{$record->id}"],
                    'badges' => [[static::STATUS_LABELS[$record->status] ?? $record->status, static::statusColor($record->status)]],
                    'meta' => $record->messages_count.' '.str('message')->plural($record->messages_count).' · '.$record->updated_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('id')->label('#'),
                    Tables\Columns\TextColumn::make('subject')->searchable()->limit(50)->weight('bold'),
                    Tables\Columns\TextColumn::make('user.display_name')
                        ->label('Customer')
                        ->description(fn (SupportTicket $record) => $record->user?->user_email)
                        ->searchable(['display_name', 'user_login', 'user_email']),
                    Tables\Columns\TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::STATUS_LABELS[$state] ?? $state)
                        ->color(fn (string $state) => static::statusColor($state)),
                    Tables\Columns\TextColumn::make('messages_count')->label('Messages')->alignCenter(),
                    Tables\Columns\TextColumn::make('updated_at')->label('Last activity')->since()->sortable(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(static::STATUS_LABELS)
                    ->default('open'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Open'),
            ])
            ->emptyStateHeading('Inbox clear')
            ->emptyStateDescription('Tickets waiting for a reply appear here.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('user.display_name')
                        ->label('Customer')
                        ->helperText(fn (SupportTicket $record) => $record->user?->user_email),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::STATUS_LABELS[$state] ?? $state)
                        ->color(fn (string $state) => static::statusColor($state)),
                    Infolists\Components\TextEntry::make('created_at')->label('Opened')->since(),
                ]),
            Infolists\Components\Section::make('Conversation')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('messages')
                        ->hiddenLabel()
                        ->contained(false)
                        ->schema([
                            Infolists\Components\TextEntry::make('message')
                                ->hiddenLabel()
                                ->prose()
                                ->helperText(fn (SupportTicketMessage $record) => ($record->is_from_admin ? ($record->is_automated ? 'Automated reply (AI)' : 'RaffleKings team') : 'Customer')
                                    .' · '.$record->created_at?->diffForHumans()),
                            Infolists\Components\ViewEntry::make('attachments')
                                ->hiddenLabel()
                                ->view('filament.support.attachments')
                                ->visible(fn (SupportTicketMessage $record) => ! empty($record->attachments)),
                        ]),
                ]),
        ]);
    }

    /** Reply as the signed-in admin, optionally marking the ticket resolved. */
    public static function reply(SupportTicket $ticket, string $message, bool $resolve): void
    {
        $admin = static::admin();

        app(SupportTicketService::class)->reply($ticket, $admin, $message, isFromAdmin: true);
        app(AdminAuditLogService::class)->record($admin, 'support_ticket.replied', SupportTicket::class, $ticket->id, [
            'ticket_user_id' => $ticket->user_id,
        ]);

        if ($resolve) {
            static::changeStatus($ticket, 'resolved');
        }
    }

    public static function changeStatus(SupportTicket $ticket, string $status): void
    {
        $admin = static::admin();

        app(SupportTicketService::class)->setStatus($ticket, $status);
        app(AdminAuditLogService::class)->record($admin, 'support_ticket.status_changed', SupportTicket::class, $ticket->id, [
            'status' => $status,
        ]);
    }

    /** @return array<int, Forms\Components\Component> */
    public static function replyForm(?SupportTicket $ticket = null): array
    {
        return [
            Forms\Components\Textarea::make('message')
                ->label('Your reply (emailed to the customer)')
                ->required()
                ->rows(5)
                ->maxLength(5000)
                ->hintAction($ticket ? static::draftWithAi($ticket) : null),
            Forms\Components\Toggle::make('resolve')
                ->label('Mark as resolved after sending'),
        ];
    }

    /**
     * "Draft with AI": fills the reply box with a suggested answer built from
     * the Knowledge base and this customer's balances and recent activity.
     * The team reads and edits it before sending; nothing is sent by itself.
     */
    public static function draftWithAi(SupportTicket $ticket): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('aiDraft')
            ->label('Draft with AI')
            ->icon('heroicon-o-sparkles')
            ->visible(fn () => app(\App\Services\Ai\GeminiClient::class)->switchedOn())
            ->modalHidden(fn () => ! app(\App\Services\Ai\GeminiClient::class)->available())
            ->modalHeading('Draft a reply with AI')
            ->modalDescription('It reads the conversation, the Knowledge base and this customer\'s account (balances, recent activity). Press again for a new version.')
            ->modalSubmitActionLabel('Draft it')
            ->form([
                Forms\Components\Textarea::make('instruction')->label('Anything it should know? (optional)')->rows(2)->maxLength(500)
                    ->placeholder('e.g. Tell them we have found the payment and it will show shortly'),
            ])
            ->action(function (array $data, Forms\Set $set) use ($ticket) {
                if (! app(\App\Services\Ai\GeminiClient::class)->available()) {
                    \Filament\Notifications\Notification::make()->title('AI could not draft this')->body(\App\Services\Ai\GeminiClient::NO_KEY_MESSAGE)->danger()->send();

                    return;
                }

                try {
                    $result = app(\App\Services\Ai\SupportAi::class)->answer($ticket->fresh(), (string) ($data['instruction'] ?? ''));
                } catch (\App\Exceptions\AiUnavailableException $e) {
                    \Filament\Notifications\Notification::make()->title('AI could not draft this')->body($e->getMessage())->danger()->send();

                    return;
                }

                if ($result['reply'] === '') {
                    \Filament\Notifications\Notification::make()->title('No draft')->body($result['reason'] ?: 'The AI was not sure how to answer.')->warning()->send();

                    return;
                }

                $set('message', $result['reply']);
                \Filament\Notifications\Notification::make()->title('Draft ready. Check it before sending.')
                    ->body($result['answerable'] ? null : 'The AI was not fully sure: '.$result['reason'])->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'view' => Pages\ViewSupportTicket::route('/{record}'),
        ];
    }
}
