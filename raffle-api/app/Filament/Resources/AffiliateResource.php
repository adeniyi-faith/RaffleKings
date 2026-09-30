<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\AffiliateResource\Pages;
use App\Models\Growth\Affiliate;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use App\Services\Growth\AffiliateService;
use App\Support\Features;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Growth → Affiliates (Settings → On / off → New features): influencers
 * and partners with their own link and earnings page (/affiliate),
 * separate from ordinary customer referrals. See AffiliateService.
 */
class AffiliateResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = Affiliate::class;

    protected static ?string $slug = 'affiliates';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Growth';

    protected static ?string $navigationLabel = 'Affiliates';

    protected static ?string $modelLabel = 'affiliate';

    public static function getNavigationBadge(): ?string
    {
        return Features::navigationBadge('affiliates');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    /** @var array<int, array<string, mixed>> */
    private static array $numbers = [];

    public static function numbers(Affiliate $affiliate): array
    {
        return static::$numbers[$affiliate->id] ??= app(AffiliateService::class)->dashboard($affiliate);
    }

    private static function customerLabel(?WpUser $user): string
    {
        return $user ? ($user->display_name ?: $user->user_login)." ({$user->user_login}, {$user->user_email})" : '';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Who')
                ->description('An affiliate needs their own customer account: their earnings go into its winnings, and they see their dashboard at '.url('/affiliate').' when logged in.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Their customer account')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => WpUser::query()
                            ->where(fn ($q) => $q->where('user_login', 'like', "%{$search}%")->orWhere('user_email', 'like', "%{$search}%")->orWhere('display_name', 'like', "%{$search}%"))
                            ->limit(20)->get()->mapWithKeys(fn (WpUser $u) => [$u->ID => static::customerLabel($u)]))
                        ->getOptionLabelUsing(fn ($value) => static::customerLabel(WpUser::query()->find($value)))
                        ->rule(fn (?Affiliate $record) => Rule::unique('affiliates', 'user_id')->ignore($record?->id)),
                    Forms\Components\TextInput::make('name')->required()->maxLength(100)->placeholder('e.g. Tobi (Instagram)'),
                    Forms\Components\TextInput::make('code')
                        ->label('Link name')
                        ->required()
                        ->maxLength(40)
                        ->regex('/^[A-Za-z0-9_-]+$/')
                        ->prefix(url('/go').'/')
                        ->rule(fn (?Affiliate $record) => Rule::unique('affiliates', 'code')->ignore($record?->id)),
                    Forms\Components\Toggle::make('is_active')->label('Active')->default(true)->helperText('Off: the link still opens the site, but nothing new is earned.'),
                ]),
            Forms\Components\Section::make('Earnings')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('commission_percent')->label('% of each top-up')->numeric()->minValue(0)->maxValue(50)->default(5)->required(),
                    Forms\Components\TextInput::make('commission_days')->label('For each customer\'s first (days)')->numeric()->minValue(1)->maxValue(3650)->default(90)->required(),
                    Forms\Components\TextInput::make('hold_days')->label('Held before paying (days)')->numeric()->minValue(0)->maxValue(90)->default(7)->required()
                        ->helperText('Time to spot fraud before money moves.'),
                    Forms\Components\Textarea::make('notes')->label('Staff notes')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (Affiliate $record) => $record->link()),
                Tables\Columns\TextColumn::make('commission_percent')->label('Rate')->formatStateUsing(fn ($state, Affiliate $record) => rtrim(rtrim((string) $state, '0'), '.')."% for {$record->commission_days} days"),
                Tables\Columns\TextColumn::make('clicks')->label('Clicks (30 days)')->state(fn (Affiliate $record) => static::numbers($record)['clicks_30_days']),
                Tables\Columns\TextColumn::make('signups')
                    ->label('Sign-ups')
                    ->state(fn (Affiliate $record) => static::numbers($record)['signups'])
                    ->description(fn (Affiliate $record) => static::numbers($record)['players'].' played · '.static::numbers($record)['topped_up'].' topped up'),
                Tables\Columns\TextColumn::make('earned')
                    ->label('Earned')
                    ->state(fn (Affiliate $record) => static::naira(static::numbers($record)['earned']['paid']))
                    ->description(fn (Affiliate $record) => static::naira(static::numbers($record)['earned']['waiting']).' waiting'),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('copyLink')
                    ->label('Link')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->modalHeading(fn (Affiliate $record) => "{$record->name}'s link")
                    ->modalDescription(fn (Affiliate $record) => $record->link().' — share it as it is. Anyone who signs up within '.AffiliateService::COOKIE_DAYS.' days of clicking counts.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('No affiliates yet')
            ->emptyStateDescription('Add an influencer or partner. They get a link, their own earnings page, and a share of what their customers top up.');
    }

    public static function changed(string $action, Affiliate $affiliate): void
    {
        app(AdminAuditLogService::class)->record(static::admin(), $action, Affiliate::class, $affiliate->id, [
            'name' => $affiliate->name,
            'user_id' => $affiliate->user_id,
            'rate' => $affiliate->commission_percent,
            'days' => $affiliate->commission_days,
            'active' => $affiliate->is_active,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAffiliates::route('/'),
            'create' => Pages\CreateAffiliate::route('/create'),
            'edit' => Pages\EditAffiliate::route('/{record}/edit'),
        ];
    }
}
