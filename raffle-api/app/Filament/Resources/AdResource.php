<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\AdResource\Pages;
use App\Filament\Support\AiAssist;
use App\Filament\Support\MobileCard;
use App\Models\Ads\Ad;
use App\Models\HomeItem;
use App\Models\Legacy\WpUser;
use App\Models\Raffle;
use App\Services\AdminAuditLogService;
use App\Services\Ads\AdServer;
use App\Services\Retention\MemberSegments;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Site → Ads: the on-site ads engine. Each ad picks the spots it shows in
 * (homepage, rewards, wallet, a pop-up...), where a tap goes (a page on the
 * site or an outside web address), who sees it, when, and how often. One
 * ad can have up to three versions; the site splits people between them
 * and the report shows which gets more taps. See App\Services\Ads\AdServer.
 */
class AdResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    protected static ?string $model = Ad::class;

    protected static ?string $slug = 'ads';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Ads';

    protected static ?string $modelLabel = 'ad';

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    /** @return array<string, string> Member segments and flags, for "Only customers in the groups I pick". */
    public static function groupOptions(): array
    {
        return collect(MemberSegments::SEGMENTS)->map(fn ($s) => $s[0])
            ->merge(collect(MemberSegments::FLAGS)->except(['stopped_reminders'])->map(fn ($f) => $f[0].' (flag)'))
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                Forms\Components\Section::make('The ad')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Name (only staff see it)')->required()->maxLength(120)
                            ->placeholder('e.g. Affiliate push, October'),
                        Forms\Components\Select::make('status')->options(Ad::STATUSES)->default('draft')->required()
                            ->helperText('Drafts and paused ads never show. Use "Preview" before setting it live.'),
                        Forms\Components\CheckboxList::make('placements')->label('Where it shows')
                            ->options(Ad::PLACEMENTS)->required()->columns(2)->columnSpanFull()
                            ->helperText('Spots that hold several ads swipe between them, like OPay\'s cards. A pop-up shows at most once every few hours per person (Settings → General → Ads).'),
                        Forms\Components\Select::make('look')->label('Look')->options(Ad::LOOKS)->default('card')->required()->columnSpanFull()
                            ->helperText('The pop-up and the homepage slide always use their own look.'),
                        Forms\Components\TextInput::make('priority')->label('Priority (1-10)')->numeric()->minValue(1)->maxValue(10)->default(5)->required()
                            ->helperText('Higher shows first. Ads with the same priority take turns.'),
                        Forms\Components\Toggle::make('can_close')->label('People can close it (X button)')->default(true)->inline(false),
                    ]),

                Forms\Components\Section::make('Where a tap goes')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Radio::make('target_type')->label('Send people to')
                            ->options(['page' => 'A page on this site', 'url' => 'An outside web address'])
                            ->default('page')->required()->live()->inline()->columnSpanFull(),
                        Forms\Components\Select::make('page_choice')->label('Page')
                            ->options(fn () => Ad::DESTINATIONS + static::raffleDestinations() + ['custom' => 'Another page (type it below)'])
                            ->searchable()
                            ->dehydrated(false)
                            ->live()
                            ->afterStateHydrated(function (Forms\Components\Select $component, Get $get) {
                                $target = (string) $get('target');
                                $component->state($target === '' ? null : (array_key_exists($target, Ad::DESTINATIONS + static::raffleDestinations()) ? $target : 'custom'));
                            })
                            ->afterStateUpdated(fn (?string $state, Forms\Set $set) => $state && $state !== 'custom' ? $set('target', $state) : null)
                            ->visible(fn (Get $get) => $get('target_type') === 'page'),
                        Forms\Components\TextInput::make('target')
                            ->label(fn (Get $get) => $get('target_type') === 'url' ? 'Web address' : 'Page address')
                            ->required()->maxLength(500)
                            ->placeholder(fn (Get $get) => $get('target_type') === 'url' ? 'https://partner-site.com/offer' : '/rewards')
                            ->helperText(fn (Get $get) => $get('target_type') === 'url'
                                ? 'Must start with https://. It opens in a new tab, and tracking tags (utm_source, utm_medium, utm_campaign) are added so the other site\'s Google Analytics shows the visits came from your ads.'
                                : 'Starts with /. Filled in for you when you pick a page above.')
                            ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if ($get('target_type') === 'url' && ! preg_match('#^https://[^\s/$.?\#][^\s]*$#i', (string) $value)) {
                                    $fail('Use a full web address starting with https://');
                                }
                                if ($get('target_type') === 'page' && ! Ad::isSitePath((string) $value)) {
                                    $fail('Use a page on this site, starting with / (for example /rewards).');
                                }
                            }),
                        Forms\Components\TextInput::make('utm_campaign')->label('Campaign name for tracking (optional)')->maxLength(80)
                            ->placeholder('e.g. partner-october')
                            ->helperText('Shown in the other site\'s analytics. Empty = "ad-" and this ad\'s number.')
                            ->visible(fn (Get $get) => $get('target_type') === 'url'),
                    ]),

                Forms\Components\Section::make('What it says')
                    ->description('Add a second version to test which one people tap more. Each person always sees the same version.')
                    ->schema([
                        Forms\Components\Repeater::make('variants')
                            ->relationship('variants')
                            ->orderColumn('sort_order')
                            ->minItems(1)->maxItems(3)
                            ->defaultItems(1)
                            ->addActionLabel('Add another version (A/B test)')
                            ->collapsible()
                            ->cloneable()
                            ->itemLabel(fn (array $state) => 'Version '.($state['label'] ?? '?').(filled($state['title'] ?? null) ? ': '.$state['title'] : ''))
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('label')->label('Version name')->default('A')->required()->maxLength(20),
                                Forms\Components\TextInput::make('weight')->label('Share of people')->numeric()->minValue(0)->maxValue(100)->default(1)->required()
                                    ->helperText('1 and 1 = half each. 0 = stop showing this version.'),
                                Forms\Components\TextInput::make('title')->label('Headline')->required()->maxLength(90)->columnSpanFull()
                                    ->placeholder('e.g. Cash up for grabs!')
                                    ->hintAction(AiAssist::action('a short, punchy ad headline for the site (under 40 characters)', false, fn (Get $get) => static::aiContext($get))),
                                Forms\Components\Textarea::make('text')->label('Short text')->rows(2)->maxLength(200)->columnSpanFull()
                                    ->placeholder('e.g. Invite friends and earn up to ₦6,300 bonus')
                                    ->hintAction(AiAssist::action('one line of ad text under the headline (under 90 characters)', false, fn (Get $get) => static::aiContext($get))),
                                Forms\Components\TextInput::make('button_label')->label('Button text')->maxLength(30)->placeholder('Go'),
                                Forms\Components\TextInput::make('badge')->label('Little tag (optional)')->maxLength(30)->placeholder('e.g. New, Hot, ₦7,200'),
                                Forms\Components\Select::make('theme')->label('Colour')->options(HomeItem::THEMES)->default('green')->required(),
                                Forms\Components\Select::make('icon')->label('Icon (when there is no picture)')->options(HomeItem::ICONS)->placeholder('None'),
                                Forms\Components\FileUpload::make('image_path')->label('Picture (optional)')
                                    ->image()->disk('public')->directory('ads')->visibility('public')
                                    ->maxSize(5120)
                                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                                    ->helperText('PNG, JPG or WebP, up to 5 MB. It is shrunk automatically. Wide pictures (about 3:1) suit banners; square ones suit cards and pop-ups.')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]),

            Forms\Components\Group::make()->columnSpan(['lg' => 1])->schema([
                Forms\Components\Section::make('Preview')->schema([
                    Forms\Components\Placeholder::make('preview')->hiddenLabel()
                        ->content(fn (Get $get) => view('filament.ads.preview', ['look' => $get('look'), 'variant' => static::previewVariant($get)])),
                ]),

                Forms\Components\Section::make('Who sees it')->schema([
                    Forms\Components\Select::make('audience')->options(Ad::AUDIENCES)->default('all')->required()->live(),
                    Forms\Components\CheckboxList::make('groups')->label('Groups')
                        ->options(fn () => static::groupOptions())
                        ->required(fn (Get $get) => $get('audience') === 'groups')
                        ->visible(fn (Get $get) => $get('audience') === 'groups')
                        ->helperText('From Growth → Member segments, worked out every night.'),
                    Forms\Components\Placeholder::make('break_note')->hiddenLabel()
                        ->content('Customers taking a break from playing never see ads.'),
                ]),

                Forms\Components\Section::make('When and how often')->schema([
                    Forms\Components\DateTimePicker::make('starts_at')->label('Start showing')->seconds(false)->helperText('Empty = as soon as it is live.'),
                    Forms\Components\DateTimePicker::make('ends_at')->label('Stop showing')->seconds(false)->after('starts_at')->helperText('Empty = until you pause it.'),
                    Forms\Components\TextInput::make('per_person_daily')->label('Most times one person sees it a day')->numeric()->minValue(1)->maxValue(100)->default(3)
                        ->helperText('Empty = no limit. Keeps people from getting tired of it.'),
                    Forms\Components\TextInput::make('daily_views_cap')->label('Most views a day (everyone)')->numeric()->minValue(1)->helperText('Optional.'),
                    Forms\Components\TextInput::make('total_views_cap')->label('Most views ever')->numeric()->minValue(1)->helperText('Optional. The ad stops by itself when it is reached.'),
                ]),
            ]),
        ]);
    }

    /** @return array<string, string> Open raffles as destinations. */
    public static function raffleDestinations(): array
    {
        try {
            return Raffle::query()->where('status', 'published')->whereNotNull('public_id')->latest('id')->limit(30)->get(['public_id', 'title'])
                ->mapWithKeys(fn (Raffle $r) => ['/raffles/'.$r->public_id => 'Raffle: '.$r->title])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function aiContext(Get $get): string
    {
        $target = (string) ($get('../../target') ?? '');

        return 'This is an ad inside the site. A tap opens: '.(Ad::DESTINATIONS[$target] ?? ($target ?: 'a page')).'.';
    }

    /** The first version, for the preview box. */
    private static function previewVariant(Get $get): array
    {
        $variant = collect((array) $get('variants'))->first() ?? [];
        $image = $variant['image_path'] ?? null;
        $image = is_array($image) ? collect($image)->first() : $image;

        return [
            'title' => $variant['title'] ?? null,
            'text' => $variant['text'] ?? null,
            'badge' => $variant['badge'] ?? null,
            'button' => ($variant['button_label'] ?? null) ?: 'Go',
            'theme' => $variant['theme'] ?? 'green',
            'image' => is_string($image) && $image !== '' ? Storage::disk('public')->url($image) : null,
        ];
    }

    public static function table(Table $table): Table
    {
        $totals = null;
        $stats = function (Ad $ad) use (&$totals) {
            $totals ??= AdServer::totals(7);

            return $totals[$ad->id] ?? ['views' => 0, 'clicks' => 0];
        };

        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                MobileCard::make(fn (Ad $record) => [
                    'title' => $record->name,
                    'lines' => [
                        collect((array) $record->placements)->map(fn ($p) => Ad::PLACEMENTS[$p] ?? $p)->implode(' · '),
                        number_format($stats($record)['views']).' views · '.number_format($stats($record)['clicks']).' taps · '.AdServer::rate($stats($record)['clicks'], $stats($record)['views']).'% (7 days)',
                    ],
                    'badges' => [[$record->stage(), static::stageColour($record->stage())]],
                    'meta' => 'Goes to '.$record->target,
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('name')->searchable()->wrap()
                        ->description(fn (Ad $record) => 'Goes to '.$record->target),
                    Tables\Columns\TextColumn::make('placements')->label('Where')
                        ->formatStateUsing(fn ($state) => Ad::PLACEMENTS[$state] ?? $state)->badge()->color('gray'),
                    Tables\Columns\TextColumn::make('stage')->badge()->state(fn (Ad $record) => $record->stage())
                        ->color(fn (string $state) => static::stageColour($state)),
                    Tables\Columns\TextColumn::make('views_7')->label('Views (7 days)')->state(fn (Ad $record) => number_format($stats($record)['views'])),
                    Tables\Columns\TextColumn::make('clicks_7')->label('Taps')->state(fn (Ad $record) => number_format($stats($record)['clicks'])),
                    Tables\Columns\TextColumn::make('rate_7')->label('Tap rate')->state(fn (Ad $record) => AdServer::rate($stats($record)['clicks'], $stats($record)['views']).'%'),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(Ad::STATUSES),
            ])
            ->actions([
                Tables\Actions\Action::make('report')
                    ->label('Report')
                    ->icon('heroicon-o-chart-bar')
                    ->color('gray')
                    ->modalHeading(fn (Ad $record) => 'Report: '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Ad $record) => view('filament.ads.report', ['ad' => $record, 'report' => app(AdServer::class)->report($record->load('variants'))])),
                Tables\Actions\Action::make('golive')
                    ->label(fn (Ad $record) => $record->status === 'live' ? 'Pause' : 'Go live')
                    ->icon(fn (Ad $record) => $record->status === 'live' ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (Ad $record) => $record->status === 'live' ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Ad $record) => $record->status === 'live' ? 'It stops showing within a minute.' : 'It starts showing within a minute (from its start time, if it has one).')
                    ->action(fn (Ad $record) => static::attempt(function () use ($record) {
                        $live = $record->status !== 'live';
                        if ($live && $record->variants()->doesntExist()) {
                            throw new \RuntimeException('Add what the ad says first.');
                        }
                        $record->update(['status' => $live ? 'live' : 'paused', 'updated_by' => static::admin()->ID]);
                        static::changed($live ? 'ad.live' : 'ad.paused', $record);
                    }, 'Done.')),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()
                    ->label('Copy')
                    ->excludeAttributes(['status', 'created_by', 'updated_by'])
                    ->beforeReplicaSaved(fn (Ad $replica) => $replica->fill(['status' => 'draft', 'name' => $replica->name.' (copy)']))
                    ->after(function (Ad $replica, Ad $record) {
                        foreach ($record->variants as $variant) {
                            $replica->variants()->create($variant->only(['label', 'title', 'text', 'badge', 'button_label', 'image_path', 'icon', 'theme', 'weight', 'sort_order']));
                        }
                    }),
                Tables\Actions\DeleteAction::make()->after(fn (Ad $record) => static::changed('ad.deleted', $record)),
            ])
            ->emptyStateHeading('No ads yet')
            ->emptyStateDescription('Promote any part of the site (affiliates, daily rewards, a raffle) or an outside link. Press "New ad" to start.');
    }

    public static function stageColour(string $stage): string
    {
        return match ($stage) {
            'Live' => 'success',
            'Scheduled' => 'info',
            'Paused' => 'warning',
            default => 'gray',
        };
    }

    public static function changed(string $action, Ad $ad): void
    {
        if (($admin = auth('wordpress')->user()) instanceof WpUser) {
            app(AdminAuditLogService::class)->record($admin, $action, Ad::class, $ad->id, ['name' => $ad->name, 'status' => $ad->status]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAds::route('/'),
            'create' => Pages\CreateAd::route('/create'),
            'edit' => Pages\EditAd::route('/{record}/edit'),
        ];
    }
}
