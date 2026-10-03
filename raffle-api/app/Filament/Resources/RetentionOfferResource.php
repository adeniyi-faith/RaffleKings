<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\Legacy\WpUserResource;
use App\Filament\Resources\RetentionOfferResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Legacy\WpUser;
use App\Models\Retention\RetentionOffer;
use App\Services\Retention\ComebackOffers;
use App\Services\Retention\MemberSegments;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Growth → Comeback offers: every personal offer sent, to whom, what it
 * was, and what came of it. An offer still waiting can be cancelled.
 * Offers are made automatically (App\Services\Retention\ComebackOffers);
 * the switch and budgets are in Settings → Reminders → Comeback offers.
 */
class RetentionOfferResource extends Resource
{
    use GuardedByStaffRole;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

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

    protected static ?string $model = RetentionOffer::class;

    protected static ?string $slug = 'comeback-offers';

    protected static ?string $navigationIcon = 'heroicon-o-gift-top';

    protected static ?string $navigationGroup = 'Growth';

    protected static ?string $navigationLabel = 'Comeback offers';

    protected static ?string $modelLabel = 'comeback offer';

    protected static ?int $navigationSort = 1;

    public static function status(RetentionOffer $o): array
    {
        if ($o->status === 'open' && $o->expires_at->isPast()) {
            return ['Ran out', 'gray'];
        }

        return match ($o->status) {
            'open' => ['Waiting · '.$o->expires_at->diffForHumans(short: true, parts: 2).' left', 'warning'],
            'claimed' => [$o->first_purchase_at ? 'Claimed and played' : 'Claimed', 'success'],
            'cancelled' => ['Cancelled', 'gray'],
            default => ['Ran out', 'gray'],
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                MobileCard::make(fn (RetentionOffer $o) => [
                    'title' => $o->prizeText().' · '.($o->user?->display_name ?: $o->user?->user_login),
                    'lines' => [$o->headline, MemberSegments::label($o->segment)],
                    'badges' => [static::status($o)],
                    'meta' => $o->created_at?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('Sent')->dateTime('j M, H:i')->timezone(config('raffles.timezone'))->sortable(),
                    Tables\Columns\TextColumn::make('user.user_login')->label('Customer')
                        ->description(fn (RetentionOffer $o) => $o->user?->user_email)
                        ->url(fn (RetentionOffer $o) => $o->user ? WpUserResource::getUrl('view', ['record' => $o->user]) : null),
                    Tables\Columns\TextColumn::make('amount')->label('Offer')->state(fn (RetentionOffer $o) => $o->prizeText())
                        ->description(fn (RetentionOffer $o) => RetentionOffer::KINDS[$o->kind] ?? $o->kind),
                    Tables\Columns\TextColumn::make('segment')->formatStateUsing(fn ($state) => MemberSegments::label($state)),
                    Tables\Columns\TextColumn::make('headline')->label('Message')->limit(45)->tooltip(fn (RetentionOffer $o) => $o->body)
                        ->description(fn (RetentionOffer $o) => ($o->written_by === 'ai' ? 'Written by Gemini' : 'Built-in message').' · '.collect($o->channels)->map(fn ($c) => ['inbox' => 'Site', 'email' => 'Email', 'push' => 'Push'][$c] ?? $c)->implode(', ')),
                    Tables\Columns\TextColumn::make('status')->badge()->state(fn (RetentionOffer $o) => static::status($o)[0])->color(fn (RetentionOffer $o) => static::status($o)[1]),
                    Tables\Columns\TextColumn::make('spend_after')->label('Spent in 7 days after')->money('NGN')->placeholder('–')
                        ->state(fn (RetentionOffer $o) => $o->status === 'claimed' ? $o->spend_after : null),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(RetentionOffer::STATUSES),
                Tables\Filters\SelectFilter::make('segment')->options(array_map(fn ($s) => $s[0], MemberSegments::SEGMENTS)),
                Tables\Filters\SelectFilter::make('kind')->label('Offer')->options(RetentionOffer::KINDS),
            ])
            ->actions([
                Tables\Actions\Action::make('cancel')->label('Cancel')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (RetentionOffer $o) => $o->isClaimable())
                    ->requiresConfirmation()
                    ->modalDescription('The customer can no longer claim it. Nothing has been paid yet.')
                    ->action(function (RetentionOffer $o) {
                        $admin = auth('wordpress')->user();
                        if ($admin instanceof WpUser && app(ComebackOffers::class)->cancel($o, $admin)) {
                            Notification::make()->title('Offer cancelled')->success()->send();
                        }
                    }),
            ])
            ->emptyStateHeading('No comeback offers yet')
            ->emptyStateDescription('Switch them on in Settings → Reminders → Comeback offers. They go out once a day to customers who are slipping away.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRetentionOffers::route('/')];
    }
}
