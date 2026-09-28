<?php

namespace App\Filament\Resources\Legacy\WpUserResource\RelationManagers;

use App\Filament\Resources\AdminAuditLogResource;
use App\Filament\Support\MobileCard;
use App\Models\AdminAuditLog;
use App\Models\Deposit;
use App\Models\Legacy\RaffleTransaction;
use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUser;
use App\Models\SupportTicket;
use App\Models\WithdrawalRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every admin action about this customer: on the account itself (ban,
 * balance change, chat mute) AND on their withdrawals, bank transfers,
 * top-ups, prizes and support tickets.
 */
class AdminActionsRelationManager extends RelationManager
{
    protected static string $relationship = 'adminActions';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Admin actions';

    protected static ?string $icon = 'heroicon-o-clipboard-document-list';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $userId = $this->getOwnerRecord()->getKey();

        return $table
            ->query(fn () => AdminAuditLog::query()->with('admin')->where(function (Builder $q) use ($userId) {
                $q->where(fn ($q) => $q->where('subject_type', WpUser::class)->where('subject_id', $userId));

                foreach ([
                    WithdrawalRequest::class => WithdrawalRequest::query(),
                    RaffleTransaction::class => RaffleTransaction::query(),
                    Deposit::class => Deposit::query(),
                    RaffleWinner::class => RaffleWinner::query(),
                    SupportTicket::class => SupportTicket::query(),
                ] as $type => $records) {
                    $q->orWhere(fn ($q) => $q->where('subject_type', $type)->whereIn('subject_id', $records->where('user_id', $userId)->select('id')));
                }
            }))
            ->defaultSort('created_at', 'desc')
            ->columns([
                MobileCard::make(fn (AdminAuditLog $log) => [
                    'title' => AdminAuditLogResource::describe($log->action),
                    'lines' => ['By '.($log->admin?->display_name ?: 'Unknown')],
                    'meta' => $log->created_at?->format('j M Y, H:i'),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('j M Y, H:i'),
                    Tables\Columns\TextColumn::make('action')->label('What')->formatStateUsing(fn ($state) => AdminAuditLogResource::describe($state)),
                    Tables\Columns\TextColumn::make('admin.display_name')->label('By')->placeholder('Unknown'),
                ]),
            ])
            ->emptyStateHeading('No admin actions on this customer');
    }
}
