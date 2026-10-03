<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\BroadcastResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Admin\CustomerTag;
use App\Models\Broadcast;
use App\Models\Growth\Affiliate;
use App\Models\Growth\PromoCode;
use App\Models\Legacy\RaffleNotificationTemplate;
use App\Models\Legacy\RaffleSiteNotice;
use App\Models\Legacy\WpUser;
use App\Models\Retention\MemberProfile;
use App\Services\Messaging\Audience;
use App\Services\Retention\DeliveryTracker;
use App\Services\Retention\MemberSegments;
use App\Filament\Support\AiAssist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Site → Message customers (item 45b): write once, send to a group — on
 * the site (the message bell), by email and/or as a phone notification.
 * Pick who: everyone, a raffle's buyers, lapsed players, people with
 * money or winnings sitting unused, one customer, a group built by
 * combining filters, or the customers ticked on the Customers list. A
 * message can go now or at a chosen time.
 */
class BroadcastResource extends Resource
{
    use GuardedByStaffRole;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    /** Where a ticked-customers list waits for the message form (see WpUserResource's bulk action). */
    public const TICKED_CACHE_PREFIX = 'broadcast-ticked:';

    protected static ?string $model = Broadcast::class;

    protected static ?string $slug = 'messages';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Message customers';

    protected static ?string $modelLabel = 'message';

    protected static ?int $navigationSort = 1;

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * The audience settings from the form (or the saved form state).
     *
     * @param  Get|array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function audienceOptions(Get|array $source): array
    {
        $value = fn (string $key) => $source instanceof Get ? $source($key) : ($source[$key] ?? null);

        return array_filter([
            'raffle_id' => $value('raffle_id'),
            'days' => $value('days'),
            'min_amount' => $value('min_amount'),
            'user_id' => $value('user_id'),
            'user_ids' => array_map('intval', (array) $value('user_ids')),
            'segments' => array_values(array_filter((array) $value('segments'))),
            'flags' => array_values(array_filter((array) $value('flags'))),
            'filters' => Audience::cleanFilters((array) $value('filters')),
        ], fn ($v) => filled($v));
    }

    /** @return array<string, string> segment => "Name (count)" */
    public static function segmentOptions(): array
    {
        $counts = MemberProfile::query()->groupBy('segment')->selectRaw('segment, COUNT(*) as n')->pluck('n', 'segment');

        return collect(MemberSegments::SEGMENTS)->mapWithKeys(fn ($s, $key) => [$key => $s[0].' ('.number_format((int) ($counts[$key] ?? 0)).')'])->all();
    }

    /** @return array<string, string> */
    public static function flagOptions(): array
    {
        return collect(MemberSegments::FLAGS)->except('stopped_reminders')->map(fn ($f) => $f[0])->all();
    }

    /** The boxes for "Build my own group". Each one that's filled in must match. */
    private static function filterFields(): array
    {
        $number = fn (string $key, string $label, ?string $suffix = null) => Forms\Components\TextInput::make("filters.{$key}")->label($label)
            ->numeric()->minValue(1)->suffix($suffix)->live(onBlur: true);

        return [
            Forms\Components\Placeholder::make('filters_help')->hiddenLabel()->columnSpanFull()
                ->content('Fill in any of these. A customer must match every box you fill in. Leave a box empty to ignore it.'),
            Forms\Components\Fieldset::make('When they joined and played')->columns(['md' => 2])->schema([
                $number('joined_within_days', 'Joined in the last', 'days'),
                $number('joined_before_days', 'Joined more than', 'days ago'),
                $number('played_within_days', 'Bought tickets in the last', 'days'),
                $number('not_played_for_days', 'Has bought before, but not in the last', 'days'),
                Forms\Components\Toggle::make('filters.never_bought')->label('Only people who never bought a ticket')->live(),
                Forms\Components\Select::make('filters.raffle_id')->label('Bought tickets in this raffle')->options(fn () => TicketResource::raffleOptions())->searchable()->live(),
            ]),
            Forms\Components\Fieldset::make('Money')->columns(['md' => 3])->schema([
                Forms\Components\TextInput::make('filters.min_wallet')->label('Spending wallet at least')->prefix('₦')->numeric()->minValue(1)->live(onBlur: true),
                Forms\Components\TextInput::make('filters.min_winnings')->label('Winnings at least')->prefix('₦')->numeric()->minValue(1)->live(onBlur: true),
                Forms\Components\TextInput::make('filters.min_spent')->label('Spent on tickets, at least')->prefix('₦')->numeric()->minValue(1)->live(onBlur: true),
            ]),
            Forms\Components\Fieldset::make('Labels and where they came from')->columns(['md' => 2])->schema([
                Forms\Components\Select::make('filters.tags')->label('Has any of these tags')->multiple()->options(fn () => array_combine(CustomerTag::inUse(), CustomerTag::inUse()))->live(),
                Forms\Components\Select::make('filters.exclude_tags')->label('Doesn\'t have any of these tags')->multiple()->options(fn () => array_combine(CustomerTag::inUse(), CustomerTag::inUse()))->live(),
                Forms\Components\Select::make('filters.promo_code_id')->label('Signed up with promo code')->options(fn () => PromoCode::query()->orderBy('code')->pluck('code', 'id'))->searchable()->live(),
                Forms\Components\Select::make('filters.affiliate_id')->label('Brought by affiliate')->options(fn () => Affiliate::query()->orderBy('name')->pluck('name', 'id'))->searchable()->live(),
                Forms\Components\Toggle::make('filters.push_only')->label('Only people who turned on phone notifications')->live(),
            ]),
            Forms\Components\Fieldset::make('Member segments (Growth → Member segments)')->columns(['md' => 2])->schema([
                Forms\Components\Select::make('filters.segments')->label('In any of these segments')->multiple()->options(static::segmentOptions())->live(),
                Forms\Components\Select::make('filters.flags')->label('Has all of these labels')->multiple()->options(static::flagOptions())->live(),
            ]),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->columns(['lg' => 3])->schema([
            Forms\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                Forms\Components\Section::make('Who gets it')->schema([
                    Forms\Components\Select::make('audience')->options(Audience::TYPES)->default('everyone')->required()->live()->selectablePlaceholder(false),
                    Forms\Components\Select::make('raffle_id')->label('Raffle')->options(fn () => TicketResource::raffleOptions())->searchable()->required()
                        ->visible(fn (Get $get) => $get('audience') === 'raffle_buyers')->live(),
                    Forms\Components\TextInput::make('days')->label('No tickets for at least (days)')->integer()->minValue(1)->default(30)->required()
                        ->visible(fn (Get $get) => $get('audience') === 'inactive')->live(onBlur: true),
                    Forms\Components\TextInput::make('min_amount')->label('At least')->prefix('₦')->numeric()->minValue(1)->default(500)->required()
                        ->visible(fn (Get $get) => in_array($get('audience'), ['wallet_balance', 'unwithdrawn_winnings'], true))->live(onBlur: true),
                    Forms\Components\Select::make('user_id')->label('Customer')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => WpUser::query()
                            ->where(fn ($q) => $q->where('user_email', 'like', "%{$search}%")->orWhere('user_login', 'like', "%{$search}%")->orWhere('display_name', 'like', "%{$search}%"))
                            ->limit(20)->get()->mapWithKeys(fn (WpUser $u) => [$u->ID => ($u->display_name ?: $u->user_login).' · '.$u->user_email]))
                        ->getOptionLabelUsing(fn ($value) => WpUser::find($value)?->user_email)
                        ->visible(fn (Get $get) => $get('audience') === 'one')->live(),
                    Forms\Components\Select::make('segments')->label('Segments')->multiple()->options(static::segmentOptions())->required()
                        ->helperText('Customers in any of these. Sorted every night.')
                        ->visible(fn (Get $get) => $get('audience') === 'segment')->live(),
                    Forms\Components\Select::make('flags')->label('Only those with all of these labels (optional)')->multiple()->options(static::flagOptions())
                        ->visible(fn (Get $get) => $get('audience') === 'segment')->live(),
                    Forms\Components\Group::make(static::filterFields())->columnSpanFull()
                        ->visible(fn (Get $get) => $get('audience') === 'custom'),
                    Forms\Components\Hidden::make('user_ids')->default([]),
                    Forms\Components\Placeholder::make('ticked')->label('Customers you ticked')
                        ->visible(fn (Get $get) => $get('audience') === 'selected')
                        ->content(fn (Get $get) => count((array) $get('user_ids')) > 0
                            ? number_format(count((array) $get('user_ids'))).' customers from the Customers list. This exact list is the only group that gets the message.'
                            : new HtmlString('No customers picked yet. On the <b>Customers</b> list, tick the customers you want, then choose <b>Message these customers</b>.')),
                    Forms\Components\Toggle::make('is_promotion')->label('This is a promotion')->default(true)->live()
                        ->helperText('Leaves out customers who tapped "stop reminders". Turn it off for something they must know, like a cancelled raffle.'),
                    Forms\Components\Placeholder::make('reach')->label('This will reach')
                        ->content(function (Get $get) {
                            $count = app(Audience::class)->count((string) $get('audience'), static::audienceOptions($get), (bool) $get('is_promotion'));

                            return new HtmlString('<span class="text-lg font-semibold">'.number_format($count).'</span> customer'.($count === 1 ? '' : 's').' <span class="text-gray-500">(banned accounts and staff left out'.($get('is_promotion') ? ', and anyone who stopped reminders' : '').')</span>');
                        }),
                    Forms\Components\CheckboxList::make('channels')->label('Send by')
                        ->options(Broadcast::CHANNELS)
                        ->descriptions([
                            'inbox' => 'Waits behind the bell at the top of the site until they read it.',
                            'email' => 'Goes to their email address.',
                            'push' => 'Only reaches customers who turned on notifications.',
                        ])
                        ->default(['inbox', 'email'])->required()->columns(['md' => 3]),
                ]),
                Forms\Components\Section::make('Message')->schema([
                    Forms\Components\Select::make('template')->label('Start from a saved message (optional)')
                        ->options(fn () => RaffleNotificationTemplate::query()->pluck('title', 'bucket_type'))
                        ->placeholder('Write a new one')->dehydrated(false)->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            if ($template = RaffleNotificationTemplate::query()->where('bucket_type', $state)->first()) {
                                $set('title', $template->title);
                                $set('body', $template->body_text);
                            }
                        }),
                    Forms\Components\TextInput::make('title')->label('Headline / email subject')->required()->maxLength(120)->live(onBlur: true),
                    Forms\Components\Textarea::make('body')->label('Message')->required()->rows(6)->maxLength(3000)->live(onBlur: true)
                        ->helperText('{name} becomes each customer\'s first name. Leave an empty line between paragraphs.')
                        ->hintAction(AiAssist::action('a short message (email and app notification) sent to customers; you may use {name} for their first name', false, fn (Forms\Get $get) => 'Headline: '.$get('title'))),
                    Forms\Components\Grid::make(['md' => 2])->schema([
                        Forms\Components\TextInput::make('link_url')->label('Button link (optional)')->placeholder('/raffles/12 or https://…')
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if (filled($value) && ! RaffleSiteNotice::isSafeLink($value)) {
                                    $fail('Use a page on this site (starting with /) or an https:// address.');
                                }
                            })->live(onBlur: true),
                        Forms\Components\TextInput::make('link_label')->label('Button text')->placeholder('Play now')->maxLength(40)->live(onBlur: true),
                    ]),
                    Forms\Components\Toggle::make('save_template')->label('Save as a reusable message')->dehydrated(false)->live(),
                    Forms\Components\TextInput::make('template_name')->label('Save it as')->placeholder('e.g. Weekend promo')->maxLength(50)->dehydrated(false)
                        ->visible(fn (Get $get) => $get('save_template'))->required(fn (Get $get) => $get('save_template')),
                ]),
                Forms\Components\Section::make('When')->schema([
                    Forms\Components\Radio::make('when')->options(['now' => 'Send it now', 'later' => 'Send it at a time I choose'])->default('now')->inline()->live()->dehydrated(false),
                    Forms\Components\DateTimePicker::make('scheduled_at')->label('Send at')->seconds(false)
                        ->timezone(config('raffles.timezone'))
                        ->minDate(now())
                        ->helperText('In '.config('raffles.timezone').' time. It goes out within a minute of this time. You can change or cancel it until then.')
                        ->visible(fn (Get $get) => $get('when') === 'later')->required(fn (Get $get) => $get('when') === 'later')->live(),
                ]),
            ]),
            Forms\Components\Section::make('Preview')->columnSpan(['lg' => 1])->schema([
                Forms\Components\Placeholder::make('preview')->hiddenLabel()->content(fn (Get $get) => view('filament.broadcast-preview', [
                    'title' => str_replace('{name}', 'Ada', (string) $get('title')),
                    'body' => str_replace('{name}', 'Ada', (string) $get('body')),
                    'label' => $get('link_label') ?: ($get('link_url') ? 'Open' : null),
                ])),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Broadcast $b) => static::getUrl('view', ['record' => $b]))
            ->columns([
                MobileCard::make(fn (Broadcast $b) => [
                    'title' => $b->title,
                    'lines' => [app(Audience::class)->describe($b->audience, $b->audience_options ?? []), static::reach($b).' · '.static::channelList($b)],
                    'badges' => [static::status($b)],
                    'meta' => ($b->sent_at ?? $b->created_at)?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('Written')->dateTime('j M Y, H:i')->description(fn (Broadcast $b) => 'by '.($b->sender?->display_name ?: 'unknown')),
                    Tables\Columns\TextColumn::make('title')->weight('bold')->limit(50)->description(fn (Broadcast $b) => app(Audience::class)->describe($b->audience, $b->audience_options ?? [])),
                    Tables\Columns\TextColumn::make('recipients_count')->label('Customers')->state(fn (Broadcast $b) => static::reach($b)),
                    Tables\Columns\TextColumn::make('channels')->label('By')->state(fn (Broadcast $b) => static::channelList($b)),
                    Tables\Columns\TextColumn::make('status')->badge()->state(fn (Broadcast $b) => static::status($b)[0])->color(fn (Broadcast $b) => static::status($b)[1]),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'scheduled' => 'Scheduled', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Stopped with an error', 'cancelled' => 'Cancelled',
                ]),
            ])
            ->emptyStateHeading('No messages sent yet')
            ->emptyStateDescription('Tell customers about a new raffle, a promotion, or remind them about winnings waiting.');
    }

    /** "1,240 customers", or "about 1,240 customers" until it has been sent. */
    public static function reach(Broadcast $b): string
    {
        $n = in_array($b->status, ['scheduled', 'sent'], true) ? $b->recipients_count : $b->delivered_count;
        $text = number_format($n).' customer'.($n === 1 ? '' : 's');

        return match ($b->status) {
            'scheduled' => 'about '.$text,
            'sending' => $text.' so far',
            default => $text,
        };
    }

    public static function channelList(Broadcast $b): string
    {
        return collect($b->channels)->map(fn ($c) => ['inbox' => 'Site', 'email' => 'Email', 'push' => 'Push'][$c] ?? $c)->implode(', ');
    }

    public static function status(Broadcast $b): array
    {
        return match ($b->status) {
            'sent' => ['Sent', 'success'],
            'failed' => ['Stopped with an error', 'danger'],
            'cancelled' => ['Cancelled', 'gray'],
            'scheduled' => ['Scheduled for '.$b->scheduled_at?->timezone(config('raffles.timezone'))->format('j M, H:i'), 'info'],
            default => ['Sending… '.number_format($b->delivered_count).' so far', 'warning'],
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Components\Section::make()->columns(['default' => 2, 'md' => 4])->schema([
                Components\TextEntry::make('status')->badge()->state(fn (Broadcast $b) => static::status($b)[0])->color(fn (Broadcast $b) => static::status($b)[1]),
                Components\TextEntry::make('recipients_count')->label('Customers')->state(fn (Broadcast $b) => static::reach($b)),
                Components\TextEntry::make('skipped')->label('Left out (stopped reminders)')
                    ->state(fn (Broadcast $b) => $b->is_promotion ? number_format($b->skipped_count) : 'Not a promotion')
                    ->visible(fn (Broadcast $b) => $b->status === 'sent'),
                Components\TextEntry::make('problem')->label('What went wrong')->state(fn (Broadcast $b) => $b->error)
                    ->visible(fn (Broadcast $b) => filled($b->error))->columnSpanFull()->color('danger'),
                Components\TextEntry::make('read')->label('Read on the site')
                    ->state(fn (Broadcast $b) => in_array('inbox', $b->channels, true)
                        ? number_format($b->inboxMessages()->whereNotNull('read_at')->count()).' of '.number_format($b->inboxMessages()->count())
                        : 'Not sent to the site'),
                Components\TextEntry::make('channels')->label('Sent by')->state(fn (Broadcast $b) => static::channelList($b)),
                Components\TextEntry::make('audience')->label('To')->state(fn (Broadcast $b) => app(Audience::class)->describe($b->audience, $b->audience_options ?? []))->columnSpan(2),
                Components\TextEntry::make('sender.display_name')->label('Written by'),
                Components\TextEntry::make('created_at')->label('Written')->dateTime('j M Y, H:i'),
                Components\TextEntry::make('scheduled_at')->label('Goes out at')->dateTime('j M Y, H:i')->timezone(config('raffles.timezone'))->visible(fn (Broadcast $b) => $b->scheduled_at !== null),
                Components\TextEntry::make('sent_at')->label('Finished')->dateTime('j M Y, H:i')->timezone(config('raffles.timezone'))->visible(fn (Broadcast $b) => $b->sent_at !== null),
            ]),
            Components\Section::make('Delivery')->description('Sent = the email or push provider took it. Tapped = they pressed the button. Email opens are a low estimate: many email apps hide them.')
                ->visible(fn (Broadcast $b) => $b->status !== 'scheduled')
                ->schema([
                    Components\TextEntry::make('delivery')->hiddenLabel()->html()->state(fn (Broadcast $b) => static::deliveryTable($b)),
                ]),
            Components\Section::make('Message')->schema([
                Components\TextEntry::make('title')->hiddenLabel()->weight('bold'),
                Components\TextEntry::make('body')->hiddenLabel()->formatStateUsing(fn ($state) => nl2br(e($state)))->html(),
                Components\TextEntry::make('link_url')->label('Button')->placeholder('None')
                    ->formatStateUsing(fn ($state, Broadcast $b) => ($b->link_label ?: 'Open').' → '.$state),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBroadcasts::route('/'),
            'create' => Pages\CreateBroadcast::route('/new'),
            'view' => Pages\ViewBroadcast::route('/{record}'),
        ];
    }

    /** Per channel: sent, waiting, failed, opened, tapped. */
    public static function deliveryTable(Broadcast $b): HtmlString
    {
        $stats = app(DeliveryTracker::class)->stats('broadcast', $b->id);
        $pct = fn (int $n, int $of) => $of > 0 ? ' <span class="text-gray-500">('.round($n / $of * 100).'%)</span>' : '';
        $rows = '';

        foreach ($b->channels as $channel) {
            $c = $stats[$channel] ?? null;
            if (! $c) {
                continue;
            }

            $rows .= '<tr class="border-t border-gray-100 dark:border-white/5"><td class="py-2 pr-4 font-medium">'.e(Broadcast::CHANNELS[$channel] ?? $channel).'</td>'
                .'<td class="pr-4 tabular-nums">'.number_format($c['sent']).'</td>'
                .'<td class="pr-4 tabular-nums">'.number_format($c['queued']).'</td>'
                .'<td class="pr-4 tabular-nums">'.number_format($c['failed']).'</td>'
                .'<td class="pr-4 tabular-nums">'.number_format($c['opened']).$pct($c['opened'], $c['sent']).'</td>'
                .'<td class="tabular-nums">'.number_format($c['clicked']).$pct($c['clicked'], $c['sent']).'</td></tr>';
        }

        if ($rows === '') {
            return new HtmlString('<p class="text-sm text-gray-500">No delivery details for this message (sent before tracking started).</p>');
        }

        return new HtmlString('<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs text-gray-500"><tr><th class="py-2 pr-4">Channel</th><th class="pr-4">Sent</th><th class="pr-4">Waiting</th><th class="pr-4">Failed</th><th class="pr-4">Opened / read</th><th>Tapped</th></tr></thead><tbody>'.$rows.'</tbody></table></div>');
    }
}
