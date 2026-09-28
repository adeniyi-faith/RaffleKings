<?php

namespace App\Filament\Resources\Legacy\WpUserResource\RelationManagers;

use App\Filament\Resources\SupportTicketResource;
use App\Filament\Support\MobileCard;
use App\Models\SupportTicket;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SupportTicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'supportTickets';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Support';

    protected static ?string $icon = 'heroicon-o-chat-bubble-left-right';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (SupportTicket $t) => SupportTicketResource::getUrl('view', ['record' => $t]))
            ->columns([
                MobileCard::make(fn (SupportTicket $t) => [
                    'title' => $t->subject,
                    'badges' => [[SupportTicketResource::STATUS_LABELS[$t->status] ?? $t->status, SupportTicketResource::statusColor($t->status)]],
                    'meta' => '#'.$t->id.' · '.$t->updated_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('id')->label('#'),
                    Tables\Columns\TextColumn::make('subject')->weight('bold')->limit(60),
                    Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => SupportTicketResource::STATUS_LABELS[$state] ?? $state)->color(fn ($state) => SupportTicketResource::statusColor($state)),
                    Tables\Columns\TextColumn::make('updated_at')->label('Last activity')->since(),
                ]),
            ])
            ->emptyStateHeading('No support tickets');
    }
}
