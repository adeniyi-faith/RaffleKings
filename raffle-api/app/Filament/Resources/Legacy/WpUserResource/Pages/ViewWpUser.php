<?php

namespace App\Filament\Resources\Legacy\WpUserResource\Pages;

use App\Filament\Resources\Legacy\WpUserResource;
use App\Models\Admin\CustomerNote;
use App\Models\Admin\CustomerTag;
use App\Services\Admin\CustomerTimeline;
use App\Services\AdminAuditLogService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\PlayLimit;
use App\Models\UserPoints;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use App\Services\ChatModerationService;
use App\Services\ResponsiblePlayService;
use App\Services\Risk\FraudWatchService;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Carbon;

/**
 * The customer profile (item 45b): everything about one customer on one
 * page — balances and lifetime totals, warning signs, bank accounts, and
 * tabs for every money movement, ticket, top-up, withdrawal, referral,
 * support ticket and admin action. Support can answer "where's my money?"
 * without opening six screens.
 */
class ViewWpUser extends ViewRecord
{
    protected static string $resource = WpUserResource::class;

    /** @var array<string, mixed>|null */
    private ?array $summary = null;

    /** Customer timeline: which kind of events, and how many to show. */
    public string $timelineKind = '';

    public int $timelineLimit = 50;

    /** @return list<array<string, mixed>> */
    public function timelineEvents(): array
    {
        return app(CustomerTimeline::class)->for($this->getRecord(), $this->timelineKind ?: null, $this->timelineLimit);
    }

    public function moreTimeline(): void
    {
        $this->timelineLimit = min(1000, $this->timelineLimit + 100);
    }

    public function updatedTimelineKind(): void
    {
        $this->timelineLimit = 50;
    }

    public function togglePin(int $noteId): void
    {
        abort_unless(WpUserResource::staffCan('customers.view'), 403);
        $note = CustomerNote::query()->where('user_id', $this->getRecord()->ID)->findOrFail($noteId);
        $note->update(['pinned' => ! $note->pinned]);
    }

    public function deleteNote(int $noteId): void
    {
        $note = CustomerNote::query()->where('user_id', $this->getRecord()->ID)->findOrFail($noteId);
        // Only the author, or someone who can manage customers, removes a note.
        abort_unless($note->author_id === auth('wordpress')->id() || WpUserResource::staffCan('customers.manage'), 403);
        $note->delete();
        app(AdminAuditLogService::class)->record(auth('wordpress')->user(), 'customer.note_deleted', \App\Models\Legacy\WpUser::class, $this->getRecord()->ID, ['note' => mb_strimwidth($note->body, 0, 200, '…')]);
        Notification::make()->title('Note deleted')->success()->send();
    }

    public function getTitle(): string
    {
        return $this->getRecord()->display_name ?: $this->getRecord()->user_login;
    }

    /** The customer's name with their profile picture beside it. */
    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $user = $this->getRecord();

        return new \Illuminate\Support\HtmlString(
            '<span style="display:flex;align-items:center;gap:14px;min-width:0">'
            .'<img src="'.e($user->avatarOrInitialsUrl()).'" alt="" width="56" height="56"'
            .' onerror="'.e($user->avatarImgAttributes()['onerror']).'"'
            .' style="flex:none;width:56px;height:56px;border-radius:9999px;object-fit:cover">'
            .'<span style="min-width:0;overflow-wrap:anywhere">'.e($this->getTitle()).'</span>'
            .'</span>'
        );
    }

    public function getSubheading(): ?string
    {
        $user = $this->getRecord();

        return '@'.$user->user_login.' · #'.$user->ID.' · '.$user->user_email;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Staff notes and tags: any staff member who can see the customer.
            Action::make('addNote')
                ->label('Add note')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->form([
                    Forms\Components\Textarea::make('body')->label('Note (staff only, customers never see it)')->required()->rows(4)->maxLength(2000),
                    Forms\Components\Toggle::make('pinned')->label('Pin to the top of their page'),
                ])
                ->action(function (array $data) {
                    $user = $this->getRecord();
                    CustomerNote::create(['user_id' => $user->ID, 'author_id' => auth('wordpress')->id(), 'body' => $data['body'], 'pinned' => (bool) ($data['pinned'] ?? false)]);
                    app(AdminAuditLogService::class)->record(auth('wordpress')->user(), 'customer.note_added', \App\Models\Legacy\WpUser::class, $user->ID, ['note' => mb_strimwidth($data['body'], 0, 200, '…')]);
                    Notification::make()->title('Note saved')->success()->send();
                }),
            Action::make('tags')
                ->label('Tags')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->fillForm(fn () => ['tags' => CustomerTag::query()->where('user_id', $this->getRecord()->ID)->orderBy('tag')->pluck('tag')->all()])
                ->form([
                    Forms\Components\TagsInput::make('tags')
                        ->label('Tags (staff only)')
                        ->suggestions(fn () => CustomerTag::inUse())
                        ->placeholder('e.g. VIP, watch closely')
                        ->splitKeys(['Tab', ',']),
                ])
                ->action(function (array $data) {
                    $changes = WpUserResource::setTags($this->getRecord()->ID, $data['tags'] ?? []);
                    Notification::make()->title($changes ? 'Tags saved' : 'No change')->success()->send();
                }),
            ...WpUserResource::accountActions(table: false),
        ];
    }

    /** One set of queries for the whole summary. */
    private function summary(): array
    {
        if ($this->summary !== null) {
            return $this->summary;
        }

        /** @var WpUser $user */
        $user = $this->getRecord();
        $wallet = $user->wallet;
        $points = UserPoints::query()->where('user_id', $user->ID)->first();
        $credits = fn (string $reason) => (float) WalletLedgerEntry::query()->where('user_id', $user->ID)->where('reason', $reason)->where('direction', 'credit')->sum('amount');
        $lastLedger = WalletLedgerEntry::query()->where('user_id', $user->ID)->max('created_at');
        $lastTicket = RaffleEntry::query()->where('user_id', $user->ID)->max('created_at');
        $referrerId = $user->metaValue('referred_by');
        $mutedUntil = app(ChatModerationService::class)->mutedUntil($user->ID);

        return $this->summary = [
            'wallet' => (float) ($wallet->wallet_balance ?? 0),
            'earnings' => (float) ($wallet->earnings_balance ?? 0),
            'points' => (int) ($points->balance ?? 0),
            'streak' => (int) ($points->streak_count ?? 0),
            'tickets' => RaffleEntry::query()->where('user_id', $user->ID)->count(),
            'topped_up' => $credits('deposit'),
            'won' => $credits('prize_payout'),
            'withdrawn' => (float) WithdrawalRequest::query()->where('user_id', $user->ID)->where('status', 'paid')->sum('amount_to_send'),
            'referral_earnings' => $credits('referral_commission'),
            'friends_referred' => WpUserMeta::query()->where('meta_key', 'referred_by')->where('meta_value', (string) $user->ID)->count(),
            'last_active' => collect([$lastLedger, $lastTicket])->filter()->map(fn ($d) => Carbon::parse($d))->max(),
            'referrer' => $referrerId ? WpUser::query()->find($referrerId) : null,
            'phone' => $user->metaValue('phone'),
            'status' => array_values(array_filter([
                $user->isBanned() ? 'Banned' : null,
                $user->metaValue('rk_ban_withdraw') === '1' ? 'Withdrawals blocked' : null,
                $mutedUntil ? 'Muted in chat' : null,
            ])),
            'flags' => app(FraudWatchService::class)->flagsFor($user),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $naira = fn (float $v) => '₦'.number_format($v, $v == floor($v) ? 0 : 2);
        $s = fn (string $key) => fn () => $this->summary()[$key];

        return $infolist->schema([
            Components\Section::make('Warning signs')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('danger')
                ->description('Things worth a second look before paying this customer. Not proof of anything.')
                ->visible(fn () => $this->summary()['flags'] !== [])
                ->schema([
                    Components\RepeatableEntry::make('flags')
                        ->hiddenLabel()
                        ->state($s('flags'))
                        ->schema([Components\TextEntry::make('text')->hiddenLabel()->color('danger')])
                        ->contained(false),
                ]),

            Components\Section::make('Staff notes & tags')
                ->icon('heroicon-o-pencil-square')
                ->description('Only staff see these. Add them with the buttons at the top.')
                ->visible(fn (WpUser $record) => CustomerNote::query()->where('user_id', $record->ID)->exists() || CustomerTag::query()->where('user_id', $record->ID)->exists())
                ->collapsible()
                ->schema([
                    Components\ViewEntry::make('notes')->hiddenLabel()->view('filament.customer-notes'),
                ]),

            Components\Section::make('Balances')
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    Components\TextEntry::make('wallet')->label('Wallet')->state(fn () => $naira($this->summary()['wallet']))->size(Components\TextEntry\TextEntrySize::Large)->weight(FontWeight::Bold),
                    Components\TextEntry::make('earnings')->label('Winnings')->state(fn () => $naira($this->summary()['earnings']))->size(Components\TextEntry\TextEntrySize::Large)->weight(FontWeight::Bold),
                    Components\TextEntry::make('points')->label('Points')
                        ->state(fn () => number_format($this->summary()['points']))
                        ->helperText(fn () => 'Worth '.$naira(intdiv($this->summary()['points'], max(1, (int) config('rewards.points_per_naira')))).' · streak '.$this->summary()['streak'])
                        ->size(Components\TextEntry\TextEntrySize::Large)->weight(FontWeight::Bold),
                    Components\TextEntry::make('status')->label('Account')
                        ->state(fn () => $this->summary()['status'] ?: ['Active'])
                        ->badge()
                        ->color(fn (string $state) => $state === 'Active' ? 'success' : 'danger'),
                ]),

            // Responsible play (item 38): read-only for staff. A break can't
            // be ended early by anyone, and limits are the customer's own.
            Components\Section::make('Play limits & breaks')
                ->icon('heroicon-o-heart')
                ->description('Set by the customer on Profile → Play Limits & Breaks. Staff cannot change them.')
                ->visible(fn (WpUser $record) => PlayLimit::query()->whereKey($record->ID)->exists())
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    Components\TextEntry::make('on_break')->label('On a break until')
                        ->state(fn (WpUser $record) => app(ResponsiblePlayService::class)->excludedUntil($record->ID)?->setTimezone(config('raffles.timezone'))->format('j M Y, g:ia') ?? 'Not on a break')
                        ->color(fn (string $state) => $state === 'Not on a break' ? null : 'warning'),
                    ...collect(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'])->map(fn ($label, $period) => Components\TextEntry::make("limit_{$period}")
                        ->label("{$label} limit")
                        ->state(function (WpUser $record) use ($period, $naira) {
                            $state = app(ResponsiblePlayService::class)->state($record->ID);
                            $limit = $state['limits'][$period];

                            return ($limit === null ? 'None' : $naira($limit)).' · spent '.$naira($state['spent'][$period]);
                        }))->values()->all(),
                ]),

            Components\Section::make('Lifetime')
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    Components\TextEntry::make('topped_up')->label('Topped up')->state(fn () => $naira($this->summary()['topped_up'])),
                    Components\TextEntry::make('tickets')->label('Tickets bought')->state(fn () => number_format($this->summary()['tickets'])),
                    Components\TextEntry::make('won')->label('Won')->state(fn () => $naira($this->summary()['won'])),
                    Components\TextEntry::make('withdrawn')->label('Withdrawn (paid)')->state(fn () => $naira($this->summary()['withdrawn'])),
                    Components\TextEntry::make('referral_earnings')->label('Referral earnings')
                        ->state(fn () => $naira($this->summary()['referral_earnings']))
                        ->helperText(fn () => $this->summary()['friends_referred'].' friend(s) signed up with their link'),
                    Components\TextEntry::make('referrer')->label('Referred by')
                        ->state(fn () => $this->summary()['referrer']?->display_name ?: $this->summary()['referrer']?->user_login ?: 'Nobody')
                        ->url(fn () => $this->summary()['referrer'] ? WpUserResource::getUrl('view', ['record' => $this->summary()['referrer']]) : null),
                    Components\TextEntry::make('user_registered')->label('Joined')->since()->placeholder('Unknown')->tooltip(fn (WpUser $record) => (string) $record->user_registered),
                    Components\TextEntry::make('last_active')->label('Last money or ticket activity')
                        ->state(fn () => $this->summary()['last_active']?->diffForHumans() ?? 'Never'),
                ]),

            Components\Section::make('Contact & bank accounts')
                ->columns(['default' => 1, 'md' => 2])
                ->collapsible()
                ->schema([
                    Components\TextEntry::make('user_email')->label('Email')->copyable(),
                    Components\TextEntry::make('phone')->label('Phone')->state($s('phone'))->placeholder('Not given')->copyable(),
                    Components\RepeatableEntry::make('bankAccounts')
                        ->label('Bank accounts')
                        ->columnSpanFull()
                        ->grid(['md' => 2])
                        ->schema([
                            Components\TextEntry::make('account_number')->hiddenLabel()->copyable()->fontFamily('mono')->weight(FontWeight::Bold)
                                ->suffix(fn ($record) => $record->is_primary ? '  · primary' : ''),
                            Components\TextEntry::make('bank_name')->hiddenLabel()->formatStateUsing(fn ($state, $record) => "{$state} · {$record->account_name}"),
                        ])
                        ->placeholder('No bank account saved'),
                ]),

            Components\Section::make('Timeline')
                ->icon('heroicon-o-clock')
                ->description('Everything that happened to this customer, newest first.')
                ->collapsible()
                ->schema([
                    Components\ViewEntry::make('timeline')->hiddenLabel()->view('filament.customer-timeline'),
                ]),
        ]);
    }
}
