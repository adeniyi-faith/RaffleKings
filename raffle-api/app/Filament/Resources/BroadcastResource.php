<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Resources\BroadcastResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Broadcast;
use App\Models\Legacy\RaffleNotificationTemplate;
use App\Models\Legacy\RaffleSiteNotice;
use App\Models\Legacy\WpUser;
use App\Services\Messaging\Audience;
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
 * money or winnings sitting unused, or one customer.
 */
class BroadcastResource extends Resource
{
    use GuardedByStaffRole;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

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

    /** @return array<string, mixed> */
    public static function audienceOptions(Get $get): array
    {
        return array_filter([
            'raffle_id' => $get('raffle_id'),
            'days' => $get('days'),
            'min_amount' => $get('min_amount'),
            'user_id' => $get('user_id'),
        ], fn ($v) => filled($v));
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
                    Forms\Components\Placeholder::make('reach')->label('This will reach')
                        ->content(function (Get $get) {
                            $count = app(Audience::class)->count((string) $get('audience'), static::audienceOptions($get));

                            return new HtmlString('<span class="text-lg font-semibold">'.number_format($count).'</span> customer'.($count === 1 ? '' : 's').' <span class="text-gray-500">(banned accounts and staff left out)</span>');
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
                        ->helperText('{name} becomes each customer\'s first name. Leave an empty line between paragraphs.'),
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
                    'lines' => [app(Audience::class)->describe($b->audience, $b->audience_options ?? []), number_format($b->recipients_count).' customers · '.static::channelList($b)],
                    'badges' => [static::status($b)],
                    'meta' => ($b->sent_at ?? $b->created_at)?->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('Sent')->dateTime('j M Y, H:i')->description(fn (Broadcast $b) => 'by '.($b->sender?->display_name ?: 'unknown')),
                    Tables\Columns\TextColumn::make('title')->weight('bold')->limit(50)->description(fn (Broadcast $b) => app(Audience::class)->describe($b->audience, $b->audience_options ?? [])),
                    Tables\Columns\TextColumn::make('recipients_count')->label('Customers')->wholeNumber(),
                    Tables\Columns\TextColumn::make('channels')->label('By')->state(fn (Broadcast $b) => static::channelList($b)),
                    Tables\Columns\TextColumn::make('status')->badge()->state(fn (Broadcast $b) => static::status($b)[0])->color(fn (Broadcast $b) => static::status($b)[1]),
                ]),
            ])
            ->emptyStateHeading('No messages sent yet')
            ->emptyStateDescription('Tell customers about a new raffle, a promotion, or remind them about winnings waiting.');
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
            default => ['Sending…', 'warning'],
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Components\Section::make()->columns(['default' => 2, 'md' => 4])->schema([
                Components\TextEntry::make('status')->badge()->state(fn (Broadcast $b) => static::status($b)[0])->color(fn (Broadcast $b) => static::status($b)[1]),
                Components\TextEntry::make('recipients_count')->label('Customers')->wholeNumber(),
                Components\TextEntry::make('read')->label('Read on the site')
                    ->state(fn (Broadcast $b) => in_array('inbox', $b->channels, true)
                        ? number_format($b->inboxMessages()->whereNotNull('read_at')->count()).' of '.number_format($b->inboxMessages()->count())
                        : 'Not sent to the site'),
                Components\TextEntry::make('channels')->label('Sent by')->state(fn (Broadcast $b) => static::channelList($b)),
                Components\TextEntry::make('audience')->label('To')->state(fn (Broadcast $b) => app(Audience::class)->describe($b->audience, $b->audience_options ?? []))->columnSpan(2),
                Components\TextEntry::make('sender.display_name')->label('Sent by'),
                Components\TextEntry::make('created_at')->label('When')->dateTime('j M Y, H:i'),
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
}
