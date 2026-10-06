<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\AdminAuditLogResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\AdminAuditLog;
use App\Models\Legacy\WpUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * OVERHAUL_CHECKLIST.md item 45 — who did what, and when. Every money,
 * draw, user, support, chat and announcement action an admin takes is
 * recorded (AdminAuditLogService); before this the log could only be read
 * through the JSON API. Read-only: nothing here can be edited or deleted.
 */
class AdminAuditLogResource extends Resource
{
    use GuardedByStaffRole;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    /** Plain descriptions for the actions the app records. */
    public const ACTIONS = [
        'settings.updated' => 'Settings changed',
        'deposit.rechecked' => 'Online payment re-checked with the gateway',
        'system.job_retried' => 'Failed email/alert retried',
        'system.job_deleted' => 'Failed email/alert removed',
        'system.errors_cleared' => 'Site error list cleared',
        'report.downloaded' => 'Spreadsheet downloaded',
        'staff.role_changed' => 'Staff role changed',
        'staff.signed_in' => 'Signed in to the admin',
        'broadcast.sent' => 'Message sent to customers',
        'broadcast.scheduled' => 'Message scheduled for later',
        'broadcast.rescheduled' => 'Scheduled message moved to another time',
        'broadcast.sent_early' => 'Scheduled message sent early',
        'broadcast.resumed' => 'Message carried on after an error',
        'broadcast.cancelled' => 'Message cancelled or stopped',
        'staff.two_step_on' => 'Two-step staff sign-in switched on',
        'staff.two_step_off' => 'Two-step staff sign-in switched off',
        'tutorial.created' => 'Tutorial written',
        'tutorial.updated' => 'Tutorial edited',
        'site_page.updated' => 'Terms or About page edited',
        'tutorial.shown' => 'Tutorial put on the site',
        'tutorial.hidden' => 'Tutorial hidden',
        'tutorial.deleted' => 'Tutorial deleted',
        'settings.reset' => 'Setting put back to the server value',
        'settings.undone' => 'Settings history: change undone',
        'withdrawal.paid' => 'Withdrawal marked paid',
        'withdrawal.rejected' => 'Withdrawal rejected & refunded',
        'deposit.approved' => 'Bank-transfer top-up approved',
        'deposit.rejected' => 'Bank transfer rejected',
        'ticket_purchase.approved' => 'Bank-transfer tickets issued',
        'ticket_purchase.credited_to_wallet' => 'Bank-transfer tickets credited to wallet instead',
        'deposit.mismatch_resolved_credited' => 'Payment mismatch credited',
        'deposit.mismatch_resolved_rejected' => 'Payment mismatch rejected',
        'transaction.revoked' => 'Transaction reversed',
        'winner.credited' => 'Winner paid / prize delivered',
        'winner.visibility_toggled' => 'Winner shown / hidden on Hall of Fame',
        'draw.seed_locked' => 'Draw seed locked',
        'draw.winners_generated' => 'Draw winners generated',
        'draw.live_reveal_started' => 'Live reveal started',
        'restriction.lift_requested' => 'Asked to lift a ban or restriction',
        'user.banned' => 'User banned',
        'user.unbanned' => 'User unbanned',
        'user.balance_add' => 'Balance added',
        'user.balance_subtract' => 'Balance subtracted',
        'user.restrictions_updated' => 'User restrictions changed',
        'support_ticket.replied' => 'Support ticket replied',
        'support_ticket.status_changed' => 'Support ticket status changed',
        'chat.message_hidden' => 'Chat message hidden',
        'chat.message_restored' => 'Chat message restored',
        'chat.user_muted' => 'Chat user muted',
        'chat.user_unmuted' => 'Chat user unmuted',
        'site_notice.created' => 'Announcement created',
        'site_notice.updated' => 'Announcement edited',
        'site_notice.deleted' => 'Announcement deleted',
        'site_notice.switched_on' => 'Announcement switched on',
        'site_notice.switched_off' => 'Announcement switched off',
    ];

    protected static ?string $model = AdminAuditLog::class;

    protected static ?string $slug = 'audit-log';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit log entry';

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

    public static function details(AdminAuditLog $record): string
    {
        return collect($record->context ?? [])
            ->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)))
            ->implode(' · ');
    }

    public static function describe(string $action): string
    {
        return static::ACTIONS[$action] ?? ucfirst(str_replace(['.', '_'], [' · ', ' '], $action));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('admin');
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
                MobileCard::make(fn (AdminAuditLog $record) => [
                    'title' => static::describe($record->action),
                    'lines' => [
                        'By '.($record->admin?->display_name ?: 'Unknown').' · on '.class_basename((string) $record->subject_type).' #'.$record->subject_id,
                        str(static::details($record))->limit(120)->toString(),
                    ],
                    'meta' => $record->created_at?->format('j M Y, H:i').' · '.$record->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('j M Y, H:i')->sortable()->description(fn (AdminAuditLog $r) => $r->created_at?->diffForHumans()),
                    Tables\Columns\TextColumn::make('admin.display_name')->label('Admin')->placeholder('Unknown'),
                    Tables\Columns\TextColumn::make('action')->label('What')->formatStateUsing(fn (string $state) => static::describe($state))->searchable(),
                    Tables\Columns\TextColumn::make('subject_id')
                        ->label('On')
                        ->formatStateUsing(fn (AdminAuditLog $record) => class_basename($record->subject_type).' #'.$record->subject_id),
                    Tables\Columns\TextColumn::make('context')
                        ->label('Details')
                        ->state(fn (AdminAuditLog $record) => static::details($record))
                        ->limit(80)
                        ->wrap(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('What')
                    ->options(fn () => AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action')
                        ->mapWithKeys(fn ($a) => [$a => static::describe($a)])->all()),
                Tables\Filters\SelectFilter::make('admin_user_id')
                    ->label('Admin')
                    ->options(fn () => WpUser::query()->whereIn('ID', AdminAuditLog::query()->distinct()->select('admin_user_id'))
                        ->pluck('display_name', 'ID')->all()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d)->addDay()))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Details'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('action')->label('What')->formatStateUsing(fn (string $state) => static::describe($state)),
            Infolists\Components\TextEntry::make('admin.display_name')->label('Admin'),
            Infolists\Components\TextEntry::make('created_at')->label('When')->dateTime('j M Y, H:i:s'),
            Infolists\Components\TextEntry::make('subject_id')->label('On')->formatStateUsing(fn (AdminAuditLog $record) => class_basename($record->subject_type).' #'.$record->subject_id),
            Infolists\Components\KeyValueEntry::make('context')->label('Details')->columnSpanFull()
                ->state(fn (AdminAuditLog $record) => collect($record->context ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) var_export($v, true) : json_encode($v))->all()),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminAuditLogs::route('/'),
        ];
    }
}
